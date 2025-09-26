<?php

namespace Drupal\ai_content_migrate;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\media\Entity\MediaType;
use Drupal\media\Entity\Media;
use Drupal\taxonomy\Entity\Term;
use Drupal\node\Entity\Node;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Service that imports content from HTML using a JSON/XPath model.
 */
class Importer {

  /**
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected EntityFieldManagerInterface $entityFieldManager;

  /**
   * @var \Drupal\file\FileRepositoryInterface
   */
  protected FileRepositoryInterface $fileRepository;

  /**
   * @var \GuzzleHttp\ClientInterface
   */
  protected ClientInterface $httpClient;

  /**
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    EntityFieldManagerInterface $entity_field_manager,
    FileRepositoryInterface $file_repository,
    ClientInterface $http_client,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
    $this->fileRepository = $file_repository;
    $this->httpClient = $http_client;
    $this->logger = $logger_factory->get('ai_content_migrate');
  }

  /**
   * Convenience method: download HTML from URL and delegate to importContent().
   *
   * @param string $url
   *   Source URL to fetch.
   * @param mixed $modelToCreate
   *   JSON model (array|object|string JSON).
   *
   * @return string
   *   Created node id (string) or empty string on failure.
   */
  public function importFromUrl(string $url, mixed $modelToCreate): string {
    try {
      $resp = $this->httpClient->request('GET', $url, [
        'headers' => [
          'User-Agent' => 'Drupal Crawler/1.0',
          'Accept' => 'text/html',
        ],
        'timeout' => 20,
      ]);
      $html = (string) $resp->getBody();
    }
    catch (\Throwable $e) {
      $this->logger->error('Failed to fetch HTML for @url: @msg', ['@url' => $url, '@msg' => $e->getMessage()]);
      return '';
    }

    // Delegate to the main importer.
    return $this->importContent($html, $modelToCreate);
  }

  /**
   * Import content from a single HTML (markup or file path) using the JSON model.
   *
   * Order of operations:
   *  1) Import media (from JSON media_bundles + media xpaths)
   *  2) Import taxonomy terms (from JSON taxonomies + xpaths)
   *  3) Create the node and attach references
   *
   * @param string $html
   *   Raw HTML markup or a filesystem path to an HTML file.
   * @param mixed $modelToCreate
   *   JSON model (array|object|string JSON) with content_types, taxonomies, media_bundles.
   *
   * @return string
   *   Created node id (string) or empty string on failure.
   */
  public function importContent(string $html, mixed $modelToCreate): string {
    // --- Normalize model input to array ---
    $model = is_array($modelToCreate) ? $modelToCreate
      : (is_string($modelToCreate) ? (json_decode($modelToCreate, TRUE) ?? [])
        : (is_object($modelToCreate) ? json_decode(json_encode($modelToCreate), TRUE) : []));

    // --- Load HTML (path or markup) ---
    $baseDir = null;
    if (is_file($html)) {
      $baseDir = rtrim(dirname(realpath($html)), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
      $htmlContent = @file_get_contents($html) ?: '';
    }
    else {
      $htmlContent = $html;
    }

    if ($htmlContent === '') {
      $this->logger->warning('Empty HTML content provided to Importer::importContent().');
    }

    // --- Parse DOM/XPath ---
    libxml_use_internal_errors(TRUE);
    $dom = new \DOMDocument('1.0', 'UTF-8');
    $dom->loadHTML($htmlContent);
    libxml_clear_errors();
    $xp = new \DOMXPath($dom);

    // Helpers (closures with local scope)
    $resolveUrl = function (string $url) use ($baseDir): string {
      if ($baseDir === null || preg_match('#^https?://#i', $url) || str_starts_with($url, 'file://')) {
        return $url;
      }
      $candidate = $baseDir . ltrim($url, '/');
      return file_exists($candidate) ? 'file://' . $candidate : $url;
    };
    $firstMatch = function(array $xpaths) use ($xp) {
      foreach ($xpaths as $x) {
        $n = $xp->query($x);
        if ($n && $n->length) {
          $item = $n->item(0);
          return $item instanceof \DOMAttr ? $item->value : trim($item->textContent);
        }
      }
      return NULL;
    };
    $allMatches = function(array $xpaths) use ($xp): array {
      $out = [];
      foreach ($xpaths as $x) {
        $n = $xp->query($x);
        if ($n && $n->length) {
          foreach ($n as $item) {
            $out[] = $item instanceof \DOMAttr ? $item->value : trim($item->textContent);
          }
        }
      }
      return $out;
    };
    $cleanInt = function ($value): ?int {
      if ($value === NULL) return NULL;
      if (is_numeric($value)) return (int) $value;
      if (preg_match('/-?\d+/', (string) $value, $m)) return (int) $m[0];
      return NULL;
    };

    // Try to get a page title (for media alt/title fallback)
    $pageTitle = null;
    foreach (($model['content_types'] ?? []) as $ct) {
      foreach (($ct['fields'] ?? []) as $f) {
        if (($f['name'] ?? '') === 'title' && !empty($f['xpaths'])) {
          $pageTitle = $firstMatch($f['xpaths']);
          break 2;
        }
      }
    }
    if (!$pageTitle) {
      $pageTitle = $firstMatch(["//meta[@property='og:title']/@content", "//h1[normalize-space()]"]);
    }
    $defaultAlt = isset($model['default_alt']) && trim((string)$model['default_alt']) !== ''
      ? trim((string)$model['default_alt'])
      : 'Immagine';

    // ===== 1) MEDIA from media_bundles =====
    $createdMediaByUrl = [];   // map: url => mid
    $mediaByBundle = [];       // map: bundle => [mid,...]
    $mediaInfo = [];           // map: mid => ['fid'=>X,'alt'=>'...','source_field'=>'field_media_image']

    foreach ($model['media_bundles'] ?? [] as $bundle) {
      $bundle_id = $bundle['bundle'] ?? 'image';
      $media_type = MediaType::load($bundle_id);
      if (!$media_type) { continue; }
      $source_field = $media_type->getSource()->getSourceFieldDefinition($media_type)->getName();

      foreach ($bundle['items'] ?? [] as $item) {
        $url = $item['url'] ?? NULL;
        if (!$url) continue;
        $absUrl = $resolveUrl($url);

        // Fetch bytes
        $data = NULL;
        try {
          if (preg_match('#^https?://#i', $absUrl)) {
            $resp = $this->httpClient->get($absUrl, ['timeout' => 20]);
            $data = (string) $resp->getBody();
          }
          elseif (str_starts_with($absUrl, 'file://')) {
            $data = @file_get_contents(substr($absUrl, 7));
          }
        }
        catch (\Throwable $e) {
          $this->logger->error('Fetch media failed @u: @m', ['@u' => $absUrl, '@m' => $e->getMessage()]);
        }
        if (!$data) continue;

        $filename = basename(parse_url($absUrl, PHP_URL_PATH) ?: ('media_' . uniqid() . '.bin'));
        try {
          $file = $this->fileRepository->writeData(
            $data,
            'public://aicontent/' . $filename,
            FileSystemInterface::EXISTS_RENAME
          );
        }
        catch (\Throwable $e) {
          $this->logger->error('Write file failed: @m', ['@m' => $e->getMessage()]);
          continue;
        }

        // ALT: item.alt -> pageTitle -> defaultAlt
        $altText = '';
        if (isset($item['alt']) && trim((string)$item['alt']) !== '') {
          $altText = trim((string)$item['alt']);
        }
        elseif (!empty($pageTitle)) {
          $altText = $pageTitle;
        }
        else {
          $altText = $defaultAlt;
        }

        $media = Media::create([
          'bundle' => $bundle_id,
          'name' => $filename,
          $source_field => [
            'target_id' => $file->id(),
            'alt' => $altText,
            'title' => $altText,
          ],
          'status' => 1,
        ]);
        $media->save();

        $createdMediaByUrl[$url] = $media->id();
        $createdMediaByUrl[$absUrl] = $media->id();
        $mediaByBundle[$bundle_id][] = $media->id();
        $mediaInfo[$media->id()] = ['fid' => $file->id(), 'alt' => $altText, 'source_field' => $source_field];
      }
    }

    // Helpers to convert URL -> File/Media on-the-fly
    $downloadToFile = function (string $url) {
      try {
        if (preg_match('#^https?://#i', $url)) {
          $resp = $this->httpClient->get($url, ['timeout' => 20]);
          $data = (string) $resp->getBody();
        }
        elseif (str_starts_with($url, 'file://')) {
          $data = @file_get_contents(substr($url, 7));
        }
        else {
          return NULL;
        }
        if (!$data) return NULL;
        $filename = basename(parse_url($url, PHP_URL_PATH) ?: ('media_' . uniqid() . '.bin'));
        $file = $this->fileRepository->writeData(
          $data,
          'public://aicontent/' . $filename,
          FileSystemInterface::EXISTS_RENAME
        );
        return $file;
      }
      catch (\Throwable $e) {
        $this->logger->error('DownloadToFile failed @u: @m', ['@u' => $url, '@m' => $e->getMessage()]);
        return NULL;
      }
    };
    $createMediaFromFile = function ($fid, string $altText = 'Immagine') {
      $mtype = MediaType::load('image');
      if (!$mtype) return NULL;
      $src = $mtype->getSource()->getSourceFieldDefinition($mtype)->getName();
      $media = Media::create([
        'bundle' => 'image',
        'name' => 'img_' . $fid,
        $src => [
          'target_id' => $fid,
          'alt' => $altText,
          'title' => $altText,
        ],
        'status' => 1,
      ]);
      $media->save();
      return [$media->id(), $src, $altText];
    };

    // ===== 2) TAXONOMIES =====
    $existingVocabs = [];
    foreach ($model['taxonomies'] ?? [] as $tax) {
      $vid = $tax['vocabulary'] ?? NULL;
      if (!$vid) continue;
      $existingVocabs[$vid] = TRUE;
      foreach ($tax['terms'] ?? [] as $term_name) {
        $term_name = trim((string) $term_name);
        if ($term_name === '') continue;
        $tids = $this->entityTypeManager->getStorage('taxonomy_term')->getQuery()
          ->condition('vid', $vid)
          ->condition('name', $term_name)
          ->accessCheck(FALSE)
          ->execute();
        if (!$tids) {
          Term::create(['vid' => $vid, 'name' => $term_name])->save();
        }
      }
    }

    // Cache terms for fast lookup (lowercased name => tid)
    $termsCache = [];
    foreach (array_keys($existingVocabs) as $vid) {
      $termsCache[$vid] = [];
      $tids = $this->entityTypeManager->getStorage('taxonomy_term')->getQuery()
        ->condition('vid', $vid)
        ->accessCheck(FALSE)
        ->execute();
      if ($tids) {
        $loaded = $this->entityTypeManager->getStorage('taxonomy_term')->loadMultiple($tids);
        foreach ($loaded as $t) {
          $termsCache[$vid][mb_strtolower($t->label())] = $t->id();
        }
      }
    }

    $resolveVocabulary = function (string $fieldName) use ($model, $existingVocabs): ?string {
      $fname = strtolower($fieldName);
      if (isset($existingVocabs[$fname])) return $fname;
      $tax = $model['taxonomies'] ?? [];
      if (count($tax) === 1 && isset($tax[0]['vocabulary'])) return $tax[0]['vocabulary'];
      foreach ($tax as $t) {
        $vid = strtolower($t['vocabulary'] ?? '');
        $label = strtolower($t['label'] ?? '');
        if ($fname === 'tags' && (str_contains($vid, 'tag') || str_contains($label, 'tag'))) return $t['vocabulary'];
        if (str_contains($vid, $fname) || str_contains($label, $fname)) return $t['vocabulary'];
      }
      return NULL;
    };

    // ===== 3) NODE CREATION =====
    $node_id = '';

    foreach ($model['content_types'] ?? [] as $ct) {
      $bundle = $ct['type'] ?? NULL;
      if (!$bundle) {
        continue;
      }

      $node_values = ['type' => $bundle, 'status' => 1];
      $fieldDefs = $this->entityFieldManager->getFieldDefinitions('node', $bundle);

      foreach (($ct['fields'] ?? []) as $f) {
        $name = $f['name'] ?? NULL;
        if (!$name) continue;

        $is_title = ($name === 'title');
        $machine = $is_title ? 'title' : ('field_' . $name);
        $jsonType = $f['type'] ?? 'string';
        $xpaths   = $f['xpaths'] ?? [];
        $card     = $f['cardinality'] ?? 1;

        // Skip non-existing fields (except title)
        if (!$is_title && !isset($fieldDefs[$machine])) {
          continue;
        }

        $fieldType  = $is_title ? 'string' : $fieldDefs[$machine]->getType();
        $targetType = $is_title ? NULL      : $fieldDefs[$machine]->getSetting('target_type');

        // === IMAGE/MEDIA FIELDS ===
        $isImageField = ($fieldType === 'image') ||
          ($fieldType === 'entity_reference' && $targetType === 'media' && in_array(strtolower($name), ['image','images','screenshot','gallery','media']));

        if ($isImageField) {
          // Extract candidate URLs from XPaths; fallback to a scalar URL; then to pre-created media_bundles.
          $urls = $allMatches($xpaths);
          if (empty($urls)) {
            $maybeUrl = $firstMatch($xpaths) ?: NULL;
            if (is_string($maybeUrl) && preg_match('#^https?://#i', $maybeUrl)) {
              $urls = [$maybeUrl];
            }
          }

          // Fallback: use the first media created in bundle "image"
          if (empty($urls) && !empty($mediaByBundle['image'])) {
            if ($fieldType === 'image') {
              $mid = $mediaByBundle['image'][0];
              $node_values[$machine] = [
                'target_id' => $mediaInfo[$mid]['fid'],
                'alt'       => $mediaInfo[$mid]['alt'],
                'title'     => $mediaInfo[$mid]['alt'],
              ];
            }
            else {
              $node_values[$machine] = ['target_id' => $mediaByBundle['image'][0]];
            }
            continue;
          }

          // For each URL: create file/media if needed
          $items = [];
          foreach ($urls as $u) {
            $u = $resolveUrl(trim($u));
            if ($u === '') continue;

            if ($fieldType === 'image') {
              // image field -> needs File ID + alt/title
              $fid = NULL; $altText = $pageTitle ?: $defaultAlt;
              if (isset($createdMediaByUrl[$u])) {
                $mid = $createdMediaByUrl[$u];
                $fid = $mediaInfo[$mid]['fid'] ?? NULL;
                $altText = $mediaInfo[$mid]['alt'] ?? $altText;
              }
              if (!$fid) {
                $file = $downloadToFile($u);
                if ($file) {
                  $fid = $file->id();
                }
              }
              if ($fid) {
                $items[] = ['target_id' => $fid, 'alt' => $altText, 'title' => $altText];
              }
            }
            else {
              // entity_reference -> media
              $mid = $createdMediaByUrl[$u] ?? NULL;
              if (!$mid) {
                $file = $downloadToFile($u);
                if ($file) {
                  [$mid] = $createMediaFromFile($file->id(), $pageTitle ?: $defaultAlt);
                }
              }
              if ($mid) {
                $items[] = ['target_id' => $mid];
              }
            }
            if ($card == 1 && !empty($items)) break;
          }

          if (!empty($items)) {
            $node_values[$machine] = ($card == 1) ? reset($items) : $items;
          }
          continue;
        }

        // === TAXONOMY REFERENCES ===
        if ($fieldType === 'entity_reference' && $targetType === 'taxonomy_term') {
          $vid = $resolveVocabulary($name);
          if ($vid) {
            $labels = $allMatches($xpaths);
            if (empty($labels)) {
              foreach ($model['taxonomies'] ?? [] as $t) {
                if (($t['vocabulary'] ?? '') === $vid) { $labels = $t['terms'] ?? []; break; }
              }
            }
            $tids = [];
            foreach ($labels as $label) {
              $key = mb_strtolower(trim((string)$label));
              if ($key === '') continue;
              // Create term on-the-fly if not exists.
              if (!isset($termsCache[$vid][$key])) {
                $term = Term::create(['vid' => $vid, 'name' => trim((string)$label)]);
                $term->save();
                $termsCache[$vid][$key] = $term->id();
              }
              $tids[] = ['target_id' => $termsCache[$vid][$key]];
              if ($card == 1) break;
            }
            if ($tids) {
              $node_values[$machine] = ($card == 1) ? reset($tids) : $tids;
            }
          }
          continue;
        }

        // === SCALAR FIELDS ===
        $value = $firstMatch($xpaths);
        if ($value === NULL) {
          continue;
        }

        switch ($jsonType) {
          case 'integer':
            $value = $cleanInt($value);
            break;
          case 'text_long':
          case 'text':
          default:
            $value = trim((string) $value);
        }

        if ($is_title) {
          $node_values['title'] = $value;
        }
        else {
          $node_values[$machine] = $value;
        }
      }

      // Create the node (one per run)
      $node = Node::create($node_values);
      $node->save();
      $node_id = (string) $node->id();
      break;
    }

    return $node_id;
  }

}
