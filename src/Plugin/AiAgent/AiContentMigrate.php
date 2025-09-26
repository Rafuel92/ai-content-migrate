<?php

namespace Drupal\ai_content_migrate\Plugin\AiAgent;

use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\ai_agents\Attribute\AiAgent;
use Drupal\ai_agents\Exception\AgentProcessingException;
use Drupal\ai_agents\PluginBase\AiAgentBase;
use Drupal\ai_agents\PluginInterfaces\AiAgentInterface;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\NodeType;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\taxonomy\Entity\Term;
use Drupal\media\Entity\MediaType;


/**
 * Plugin implementation of the AI Content Migrate Agent.
 */
#[AiAgent(
  id: 'ai_content_migrate_agent',
  label: new TranslatableMarkup('AI Content Migrate Agent'),
)]
class AIContentMigrate extends AiAgentBase implements AiAgentInterface {

  use DependencySerializationTrait;

  /**
   * Questions to ask.
   *
   * @var array
   */
  protected $questions = [];

  /**
   * The full result of the task.
   *
   * @var array
   */
  protected $result;

  /**
   * The full data of the initial task.
   *
   * @var array
   */
  protected $data;

  /**
   * Task type.
   *
   * @var string
   */
  protected $taskType;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->entityFieldManager = $container->get('entity_field.manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getId() {
    return 'ai_content_migrate_agent';
  }

  /**
   * {@inheritdoc}
   */
  public function agentsNames(): array {
    return [
      'AI Content Migrate Agent',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function agentsCapabilities(): array {
    return [
      'ai_content_migrate_agent' => [
        'name' => 'AI Content Migrate Agent',
        'description' => $this->t("Handles dynamic content type suggestion, creation, media import, and node migration guided by AI."),
        'inputs' => [
          'payload' => [
            'name' => 'Payload',
            'type' => 'array',
            'description' => $this->t('Data input for AI-driven migration tasks.'),
            'default_value' => [],
          ],
        ],
        'outputs' => [
          'result' => [
            'description' => $this->t('The outcome of the AI-driven migration task.'),
            'type' => 'array',
          ],
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function setData($data): void {
    $this->data = $data;
  }

  /**
   * {@inheritdoc}
   */
  public function isAvailable() {
    return $this->agentHelper->isModuleEnabled('node') && $this->agentHelper->isModuleEnabled('media');
  }

  /**
   * {@inheritdoc}
   */
  public function isNotAvailableMessage() {
    return $this->t('You need to enable the node and media modules to use this agent.');
  }

  /**
   * {@inheritdoc}
   */
  public function getRetries(): int {
    return 2;
  }

  /**
   * {@inheritdoc}
   */
  public function getData() {
    return $this->data;
  }

  /**
   * {@inheritdoc}
   */
  public function determineSolvability(): int {
    parent::determineSolvability();
    $this->taskType = $this->determineTaskType();
    $data = $this->getData();
    if(!is_array($data) && !is_null($data)){
      $data = $this->decodeModelPayload($data);
    }
    $data[] = ['action'=>$this->taskType];
    $this->setData($data);
    switch ($this->taskType) {
      case 'applySchema':
        return AiAgentInterface::JOB_SOLVABLE;
      case 'question':
      case 'discoverSource':
        return AiAgentInterface::JOB_NEEDS_ANSWERS;
      case 'fail':
      default:
        return AiAgentInterface::JOB_NOT_SOLVABLE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function askQuestion(): array {
    $model = $this->data[0]['model'] ?? [];
    if(empty($model)){
      $model = $this->data['model'];
    }
    $this->saveLastModel($model);
    $questions = [];
    $modelProposed = $this->formatProposedModel($model);
    $questions[] = $modelProposed;
    $this->saveLastQuestion($modelProposed);
    return $questions;
  }

  /**
   * {@inheritDoc}
   */
  public function answerQuestion(): string {
    $model = $this->data[0]['model'] ?? [];
    $this->saveLastModel($model);
    return $this->formatProposedModel($model);
  }

  /**
   * {@inheritdoc}
   */
  public function solve() {
    switch ($this->data[0]['action'] ?? '') {
      case 'discoverSource':
        return $this->discoverSource();
      case 'applySchema':
        return $this->createContentTypesAndFields();
      default:
        throw new AgentProcessingException($this->t('Unknown action type.'));
    }
  }

  protected function discoverSource(): string {
    return '';
  }


  protected function createContentTypesAndFields(): string {
    $modelToCreate = $this->getLastModel();
    try {
      $this->importFromJson($modelToCreate);
    } catch (\Exception $e){
      throw new AgentProcessingException($this->t('Failed to create content types and fields.'));
    }
    $multiple = count($this->getPages()) > 1;
    try {
      if(!$multiple) {
        return $this->importContent($this->getHtmlPage(), $modelToCreate);
      } else {
        return $this->createQueue($modelToCreate);
      }
    } catch (\Exception $e){
      throw new AgentProcessingException($this->t('Failed to create content types and fields.'));
    }
  }



  /**
   * Determine task type from input data.
   */
  protected function determineTaskType(): string {
    $desc = $this->task->getDescription();

    // Collect all URLs found in the description (both full URLs and bare domains)
    $urls = [];

    // 1) Full URLs with scheme (http/https)
    if (preg_match_all('~\bhttps?://[^\s<>"\'\)\]]+~i', $desc, $m1) && !empty($m1[0])) {
      foreach ($m1[0] as $raw) {
        // Strip trailing punctuation that might be attached in prose
        $clean = rtrim($raw, ".,;:!?)]}");
        $urls[] = $clean;
      }
    }

    // 2) Domains (with or without www) + optional path/query/fragment; add https:// if missing
    if (preg_match_all('~\b(?:(?:https?:)?//)?(?:www\.)?[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9]))+(?:/[^\s<>"\'\)\]]*)?~i', $desc, $m2) && !empty($m2[0])) {
      foreach ($m2[0] as $raw) {
        $clean = rtrim($raw, ".,;:!?)]}");
        // Prepend https:// if no scheme is present
        if (stripos($clean, '://') === false) {
          $clean = 'https://' . $clean;
        }
        $urls[] = $clean;
      }
    }

    // Deduplicate URLs; order will be: full URLs first (as they appeared), then schema-less domains
    $urls = array_values(array_unique($urls, SORT_STRING));

    // Flag whether there are multiple URLs and set it via the provided setter
    if(!empty($urls)){
      $this->setPages($urls);
    }

    // Keep using the first URL (if any) for the existing logic
    $url = $urls[0] ?? '';

    // Detect "change model" command (case-insensitive)
    $isChangingModel = (stripos($desc, 'change model') !== false);

    $isDryRun = (stripos($desc, 'dry run') !== false);

    $this->setDryRun($isDryRun);
    // If no URL and not changing the model, default to 'applySchema'
    if (empty($url) && !$isChangingModel) {
      return 'applySchema';
    }

    // Optionally fetch the HTML content from the first URL (skip if changing the model)
    if (!$isChangingModel && !empty($url)) {
      try {
        $html = \Drupal::httpClient()->request('GET', $url, [
          'headers' => [
            'User-Agent' => 'Drupal Crawler/1.0',
            'Accept' => 'text/html',
          ],
          'timeout' => 10,
        ]);
        $contentHtml = $html->getBody()->getContents();
      } catch (\Throwable $e) {
        // In case of network/HTTP errors, proceed with empty content
        $contentHtml = '';
      }
    } else {
      $contentHtml = '';
    }

    // Ask the sub-agent for a proposed model based on the fetched HTML and last known model
    $data = $this->agentHelper->runSubAgent('modelProposal', [
      'html of the old website page' => $contentHtml,
      'existing model' => json_encode($this->getLastModel()),
    ]);

    // Persist the fetched HTML for later steps (only if we actually fetched it)
    if (!$isChangingModel) {
      $this->setHtmlPage($contentHtml);
    }

    // Store agent output for downstream steps
    $this->setData($data);

    // Continue with the standard flow (discover source, import media, create CT/fields, etc.)
    return 'discoverSource';
  }

  protected function scrapeWebsiteUrl(){

  }

  protected function formatProposedModel(array $data) : string {
    // Helper per escape sicuro
    $e = function ($v) {
      return \Drupal\Component\Utility\Html::escape(
        is_scalar($v) ? (string) $v : json_encode($v)
      );
    };
    // Helper per cardinalità
    $formatCardinality = function ($card) {
      $n = is_numeric($card) ? (int) $card : 1;
      if ($n === -1) {
        return $this->t('unlimited');
      }
      if ($n === 1) {
        return $this->t('single value');
      }
      return $this->t('up to @n', ['@n' => $n]);
    };
    // Helper per troncare stringhe lunghe
    $truncate = function (string $text, int $max = 80) {
      return (mb_strlen($text) > $max)
        ? (mb_substr($text, 0, $max - 1) . '…')
        : $text;
    };

    $contentTypes = $data['content_types'] ?? [];
    $taxonomies   = $data['taxonomies'] ?? [];
    $mediaBundles = $data['media_bundles'] ?? [];

    // Riepilogo numerico
    $totalFields = 0;
    $requiredFields = 0;
    foreach ($contentTypes as $ct) {
      foreach (($ct['fields'] ?? []) as $f) {
        $totalFields++;
        if (!empty($f['required'])) {
          $requiredFields++;
        }
      }
    }

    $output  = '<h2>' . $this->t('Do you want to import the following data model?') . '</h2>';
    $output .= '<p>' . $this->t('@ct content type(s), @fields field(s) (@req required), @tx taxonomie(s), @mb media bundle(s).', [
        '@ct' => count($contentTypes),
        '@fields' => $totalFields,
        '@req' => $requiredFields,
        '@tx' => count($taxonomies),
        '@mb' => count($mediaBundles),
      ]) . '</p>';

    // Content Types
    $output .= "<h2>" . $this->t('Content Types (@count)', ['@count' => count($contentTypes)]) . "</h2><ul>";
    foreach ($contentTypes as $type) {
      $label = $e($type['label'] ?? '');
      $machine = $e($type['type'] ?? '');
      $desc = trim((string) ($type['description'] ?? ''));
      $fields = $type['fields'] ?? [];
      $fieldCount = count($fields);

      $output .= "<li><strong>{$label}</strong> <code>{$machine}</code>";
      if ($desc !== '') {
        $output .= " — " . $e($desc);
      }
      $output .= "<details><summary>" . $this->t('@count field(s)', ['@count' => $fieldCount]) . "</summary><ul>";

      foreach ($fields as $f) {
        $fname = $e($f['name'] ?? '');
        $flabel = $e($f['label'] ?? '');
        $ftype = $e($f['type'] ?? '');
        $required = !empty($f['required']) ? $this->t('required') : $this->t('optional');
        $cardText = $formatCardinality($f['cardinality'] ?? 1);

        $output .= "<li><strong>{$flabel}</strong> <code>{$fname}</code> — <em>{$ftype}</em> · {$required} · "
          . $this->t('Cardinality: @card', ['@card' => $cardText]);

        $xps = $f['xpaths'] ?? [];
        if (!empty($xps)) {
          $shown = array_slice($xps, 0, 2);
          $extra = max(0, count($xps) - 2);
          $xpHtml = array_map(fn($x) => '<code>' . $e($x) . '</code>', $shown);
          $output .= "<br/>" . $this->t('XPaths:') . " " . implode(', ', $xpHtml);
          if ($extra > 0) {
            $output .= ' ' . $this->t('(+@n more)', ['@n' => $extra]);
          }
        }
        $output .= "</li>";
      }

      $output .= "</ul></details></li>";
    }
    $output .= "</ul>";

    // Taxonomies
    $output .= "<h2>" . $this->t('Taxonomies (@count)', ['@count' => count($taxonomies)]) . "</h2><ul>";
    foreach ($taxonomies as $taxonomy) {
      $label = $e($taxonomy['label'] ?? '');
      $machine = $e($taxonomy['vocabulary'] ?? '');
      $terms = $taxonomy['terms'] ?? [];
      $preview = array_slice($terms, 0, 6);
      $extra = max(0, count($terms) - 6);

      $output .= "<li><strong>{$label}</strong> <code>{$machine}</code> — "
        . $this->t('@n term(s)', ['@n' => count($terms)]) . ": "
        . $e(implode(', ', $preview));
      if ($extra > 0) {
        $output .= ' ' . $this->t('(+@n more)', ['@n' => $extra]);
      }
      $output .= "</li>";
    }
    $output .= "</ul>";

    // Media Bundles
    $output .= "<h2>" . $this->t('Media Bundles (@count)', ['@count' => count($mediaBundles)]) . "</h2><ul>";
    foreach ($mediaBundles as $bundle) {
      $bundleName = $e($bundle['bundle'] ?? '');
      $items = $bundle['items'] ?? [];
      $itemCount = count($items);

      $output .= "<li><strong>{$bundleName}</strong> — "
        . $this->t('@n item(s)', ['@n' => $itemCount]);

      if ($itemCount > 0) {
        $first = $items[0];
        $alt = $e($first['alt'] ?? '');
        $url = $e($truncate((string) ($first['url'] ?? ''), 90));
        $output .= "<br/>" . $this->t('Example:') . " <em>{$alt}</em> — <code>{$url}</code>";
      }
      $output .= "</li>";
    }
    $output .= "</ul>";

    return $output;
  }

  private function saveLastModel(array $model){
    \Drupal::state()->set('ai_content_migrate.last_model', $model);
  }

  private function saveLastQuestion(string $question){
    \Drupal::state()->set('ai_content_migrate.last_answer', $question);
  }

  private function setHtmlPage(string $contentHtml){
    \Drupal::state()->set('ai_content_migrate.html_page', $contentHtml);
  }

  private function getHtmlPage(){
    return \Drupal::state()->get('ai_content_migrate.html_page');
  }

  private function getLastQuestion(){
    return \Drupal::state()->get('ai_content_migrate.last_answer');
  }

  private function getLastModel(){
    return \Drupal::state()->get('ai_content_migrate.last_model');
  }

  private function setPages(array $urls){
    \Drupal::state()->set('ai_content_migrate.pages', $urls);
  }

  private function getPages(){
    return \Drupal::state()->get('ai_content_migrate.pages');
  }

  private function setDryRun(bool $dryRun){
    \Drupal::state()->set('ai_content_migrate.dry_run', $dryRun);
  }

  private function getDryRun(){
    return \Drupal::state()->get('ai_content_migrate.dry_run');
  }
  /**
   * Import content types, fields, taxonomies, and media bundles from a JSON file.
   *
   * @param string $json_path
   *   Absolute path to the JSON configuration file.
   *
   * @throws \Exception
   */
  public function importFromJson($data): void {
    if (!is_array($data)) {
      throw new \Exception("Invalid JSON structure");
    }

    // Service per i display
    $display_repo = \Drupal::service('entity_display.repository');

    // 1) Tassonomie + termini
    foreach ($data['taxonomies'] ?? [] as $tax) {
      $vid = $tax['vocabulary'];
      if (!\Drupal\taxonomy\Entity\Vocabulary::load($vid)) {
        if(!$this->getDryRun()) {
          \Drupal\taxonomy\Entity\Vocabulary::create([
            'vid' => $vid,
            'name' => $tax['label'] ?? $vid,
          ])->save();
        }
      }
      foreach (($tax['terms'] ?? []) as $term_label) {
        $exists = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadByProperties([
          'vid' => $vid,
          'name' => $term_label,
        ]);
        if (!$exists) {
          if(!$this->getDryRun()) {
            \Drupal\taxonomy\Entity\Term::create([
              'vid' => $vid,
              'name' => $term_label,
            ])->save();
          }
        }
      }
    }

    // 2) Media bundle "image"
    foreach ($data['media_bundles'] ?? [] as $bundle) {
      $mid = $bundle['bundle'];
      if ($mid && !\Drupal\media\Entity\MediaType::load($mid)) {
        if(!$this->getDryRun()) {
          \Drupal\media\Entity\MediaType::create([
            'id' => $mid,
            'label' => ucfirst($mid),
            'source' => $mid,
          ])->save();
        }
      }
    }

    // 3) Content types + campi
    foreach ($data['content_types'] ?? [] as $ct) {
      $type = $ct['type'];
      $label = $ct['label'] ?? $type;

      // Creazione bundle se mancante
      $node_type = \Drupal\node\Entity\NodeType::load($type);
      if (!$node_type) {
        $node_type = \Drupal\node\Entity\NodeType::create([
          'type' => $type,
          'name' => $label,
          'description' => $ct['description'] ?? '',
        ]);
        if(!$this->getDryRun()) {
          $node_type->save();
        }
      }

      // Recupero/creazione display tramite service
      $form_display = $display_repo->getFormDisplay('node', $type, 'default');
      $view_display = $display_repo->getViewDisplay('node', $type, 'default');

      // Mapping widget/formatter
      $widgetByType = [
        'string' => 'string_textfield',
        'string_long' => 'text_textarea',
        'text' => 'text_textarea',
        'text_long' => 'text_textarea',
        'text_with_summary' => 'text_textarea_with_summary',
        'boolean' => 'boolean_checkbox',
        'integer' => 'number',
        'decimal' => 'number',
        'float' => 'number',
        'entity_reference' => 'entity_reference_autocomplete',
        'image' => 'image_image',
        'file' => 'file_generic',
        'datetime' => 'datetime_default',
        'timestamp' => 'datetime_timestamp',
        'link' => 'link_default',
        'list_string' => 'options_select',
        'list_integer' => 'options_select',
      ];
      $formatterByType = [
        'string' => 'string',
        'string_long' => 'text_default',
        'text' => 'text_default',
        'text_long' => 'text_default',
        'text_with_summary' => 'text_default',
        'boolean' => 'boolean',
        'integer' => 'number_integer',
        'decimal' => 'number_decimal',
        'float' => 'number_decimal',
        'entity_reference' => 'entity_reference_label',
        'image' => 'image',
        'file' => 'file_default',
        'datetime' => 'datetime_default',
        'timestamp' => 'timestamp',
        'link' => 'link',
        'list_string' => 'list_default',
        'list_integer' => 'list_default',
      ];

      foreach ($ct['fields'] as $i => $field) {
        $name = $field['name'];
        $type_field = $field['type'];
        $label_field = $field['label'] ?? $name;
        $required = (bool) ($field['required'] ?? FALSE);
        $cardinality = (int) ($field['cardinality'] ?? 1);
        $weight = $field['weight'] ?? $i;

        // Gestione speciale per il titolo
        if ($name === 'title' && !$this->getDryRun()) {
          $node_type->set('title_label', $label_field);
          $node_type->save();
          $form_display->setComponent('title', [
            'type' => $widgetByType['string'],
            'weight' => $weight,
          ]);
          continue;
        }

        // Field API
        $field_name = 'field_' . $name;

        if (!\Drupal\field\Entity\FieldStorageConfig::loadByName('node', $field_name)) {
          $storage_settings = [];
          if ($type_field === 'list_string' && !empty($field['options'])) {
            $opts = $field['options'];
            if (array_is_list($opts)) {
              $opts = array_combine($opts, $opts);
            }
            $storage_settings['allowed_values'] = $opts;
          }
          if(!$this->getDryRun()) {
            \Drupal\field\Entity\FieldStorageConfig::create([
              'field_name' => $field_name,
              'entity_type' => 'node',
              'type' => $type_field,
              'cardinality' => $cardinality,
              'settings' => $storage_settings,
            ])->save();
          }
        }

        if (!\Drupal\field\Entity\FieldConfig::loadByName('node', $type, $field_name)) {
          if(!$this->getDryRun()) {
            \Drupal\field\Entity\FieldConfig::create([
              'field_name' => $field_name,
              'entity_type' => 'node',
              'bundle' => $type,
              'label' => $label_field,
              'required' => $required,
            ])->save();
          }
        }

        // Form display
        $widget = $field['widget'] ?? ($widgetByType[$type_field] ?? 'string_textfield');
        $form_display->setComponent($field_name, [
          'type' => $widget,
          'weight' => $weight,
        ]);

        // View display
        $formatter = $field['formatter'] ?? ($formatterByType[$type_field] ?? 'string');
        $view_display->setComponent($field_name, [
          'type' => $formatter,
          'weight' => $weight,
          'label' => $field['display_label'] ?? 'above',
        ]);
      }

      // Salva display
      if(!$this->getDryRun()) {
        $form_display->save();
        $view_display->save();
      }
    }
  }

  /**
   * Import content from a single HTML page using XPaths defined in the JSON model.
   *
   * Order of operations:
   *  1) Import media first (from JSON media_bundles and any media xpaths)
   *  2) Import taxonomy terms (from JSON taxonomies and from xpaths)
   *  3) Create content nodes and attach references
   *
   * @param string $html  Absolute path to the HTML file.
   * @param mixed  $modelToCreate JSON model (array/object/string JSON) with content_types, taxonomies, media_bundles.
   *
   * @throws \Exception
   */
  private function importContent(string $html, mixed $modelToCreate): string {
    // --- normalizza modello ---
    $model = is_array($modelToCreate) ? $modelToCreate
      : (is_string($modelToCreate) ? (json_decode($modelToCreate, TRUE) ?? [])
        : (is_object($modelToCreate) ? json_decode(json_encode($modelToCreate), TRUE) : []));

    // --- carica HTML (path o markup) ---
    $baseDir = null;
    if (is_file($html)) {
      $baseDir = rtrim(dirname(realpath($html)), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
      $htmlContent = file_get_contents($html);
    } else {
      $htmlContent = $html;
    }

    // --- parse DOM/XPath ---
    libxml_use_internal_errors(TRUE);
    $dom = new \DOMDocument('1.0', 'UTF-8');
    $dom->loadHTML($htmlContent);
    libxml_clear_errors();
    $xp = new \DOMXPath($dom);

    // helpers
    $resolveUrl = function (string $url) use ($baseDir): string {
      if ($baseDir === null || preg_match('#^https?://#i', $url) || str_starts_with($url, 'file://')) return $url;
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

    // page title per alt fallback + default_alt
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

    // ===== 1) MEDIA da media_bundles =====
    $createdMediaByUrl = [];          // url => mid
    $mediaByBundle = [];              // bundle => [mid...]
    $mediaInfo = [];                  // mid => ['fid'=>X,'alt'=>'...','source_field'=>'field_media_image']

    foreach ($model['media_bundles'] ?? [] as $bundle) {
      $bundle_id = $bundle['bundle'] ?? 'image';
      $media_type = \Drupal\media\Entity\MediaType::load($bundle_id);
      if (!$media_type) { continue; }
      $source_field = $media_type->getSource()->getSourceFieldDefinition($media_type)->getName();

      foreach ($bundle['items'] ?? [] as $item) {
        $url = $item['url'] ?? NULL;
        if (!$url) continue;
        $absUrl = $resolveUrl($url);

        // scarica bytes
        $data = NULL;
        try {
          if (preg_match('#^https?://#i', $absUrl)) {
            $resp = \Drupal::httpClient()->get($absUrl, ['timeout' => 20]);
            $data = (string) $resp->getBody();
          } elseif (str_starts_with($absUrl, 'file://')) {
            $data = @file_get_contents(substr($absUrl, 7));
          }
        } catch (\Throwable $e) {
          \Drupal::logger('aicontent')->error('Fetch media failed @u: @m', ['@u' => $absUrl, '@m' => $e->getMessage()]);
        }
        if (!$data) continue;
        if(!$this->getDryRun()){continue;}
        $filename = basename(parse_url($absUrl, PHP_URL_PATH) ?: ('media_' . uniqid() . '.bin'));
        try {
          $file = \Drupal::service('file.repository')->writeData(
            $data,
            'public://aicontent/' . $filename,
            \Drupal\Core\File\FileSystemInterface::EXISTS_RENAME
          );
        } catch (\Throwable $e) {
          \Drupal::logger('aicontent')->error('Write file failed: @m', ['@m' => $e->getMessage()]);
          continue;
        }

        // ALT: item.alt -> pageTitle -> default
        $altText = '';
        if (isset($item['alt']) && trim((string)$item['alt']) !== '') {
          $altText = trim((string)$item['alt']);
        } elseif (!empty($pageTitle)) {
          $altText = $pageTitle;
        } else {
          $altText = $defaultAlt;
        }

        $media = \Drupal\media\Entity\Media::create([
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

    // Helpers per convertire URL -> File/Media on-the-fly
    $downloadToFile = function (string $url) {
      try {
        if (preg_match('#^https?://#i', $url)) {
          $resp = \Drupal::httpClient()->get($url, ['timeout' => 20]);
          $data = (string) $resp->getBody();
        } elseif (str_starts_with($url, 'file://')) {
          $data = @file_get_contents(substr($url, 7));
        } else {
          return NULL;
        }
        if (!$data) return NULL;
        $filename = basename(parse_url($url, PHP_URL_PATH) ?: ('media_' . uniqid() . '.bin'));
        $file = \Drupal::service('file.repository')->writeData(
          $data,
          'public://aicontent/' . $filename,
          \Drupal\Core\File\FileSystemInterface::EXISTS_RENAME
        );
        return $file;
      } catch (\Throwable $e) {
        \Drupal::logger('aicontent')->error('DownloadToFile failed @u: @m', ['@u' => $url, '@m' => $e->getMessage()]);
        return NULL;
      }
    };
    $createMediaFromFile = function ($fid, string $altText = 'Immagine') {
      $mtype = \Drupal\media\Entity\MediaType::load('image');
      if (!$mtype) return NULL;
      $src = $mtype->getSource()->getSourceFieldDefinition($mtype)->getName();
      $media = \Drupal\media\Entity\Media::create([
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

    // ===== 2) TASSONOMIE =====
    $existingVocabs = [];
    foreach ($model['taxonomies'] ?? [] as $tax) {
      $vid = $tax['vocabulary'] ?? NULL;
      if (!$vid) continue;
      $existingVocabs[$vid] = TRUE;
      foreach ($tax['terms'] ?? [] as $term_name) {
        $term_name = trim((string) $term_name);
        if ($term_name === '') continue;
        $tids = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->getQuery()
          ->condition('vid', $vid)
          ->condition('name', $term_name)
          ->accessCheck(FALSE)
          ->execute();
        if (!$tids) {
          \Drupal\taxonomy\Entity\Term::create(['vid' => $vid, 'name' => $term_name])->save();
        }
      }
    }
    // Cache termini
    $termsCache = [];
    foreach (array_keys($existingVocabs) as $vid) {
      $termsCache[$vid] = [];
      $tids = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->getQuery()
        ->condition('vid', $vid)
        ->accessCheck(FALSE)
        ->execute();
      if ($tids) {
        $loaded = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadMultiple($tids);
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

    // ===== 3) NODO =====
    $entityFieldManager = \Drupal::service('entity_field.manager');

    $node_id = null;
    foreach ($model['content_types'] ?? [] as $ct) {
      $bundle = $ct['type'] ?? NULL;
      if (!$bundle) continue;

      $node_values = ['type' => $bundle, 'status' => 1];
      $fieldDefs = $entityFieldManager->getFieldDefinitions('node', $bundle);

      foreach (($ct['fields'] ?? []) as $f) {
        $name = $f['name'] ?? NULL;
        if (!$name) continue;

        $is_title = ($name === 'title');
        $machine = $is_title ? 'title' : ('field_' . $name);
        $jsonType = $f['type'] ?? 'string';
        $xpaths   = $f['xpaths'] ?? [];
        $card     = $f['cardinality'] ?? 1;

        // se il campo non esiste (tranne title), salta
        if (!$is_title && !isset($fieldDefs[$machine])) {
          continue;
        }

        $fieldType  = $is_title ? 'string' : $fieldDefs[$machine]->getType();
        $targetType = $is_title ? NULL      : $fieldDefs[$machine]->getSetting('target_type');

        // === IMMAGINI ===
        $isImageField = ($fieldType === 'image') ||
          ($fieldType === 'entity_reference' && $targetType === 'media' && in_array(strtolower($name), ['image','images','screenshot','gallery','media']));
        if ($isImageField) {
          // prova a ricavare URL da xpaths; se vuoti, prova a interpretare come scalar URL; poi fallback media_bundles
          $urls = $allMatches($xpaths);
          if (empty($urls)) {
            $maybeUrl = $firstMatch($xpaths) ?: NULL;
            if (is_string($maybeUrl) && preg_match('#^https?://#i', $maybeUrl)) {
              $urls = [$maybeUrl];
            }
          }

          // Se ancora vuoto, fallback a primo media già creato
          if (empty($urls) && !empty($mediaByBundle['image'])) {
            // usa MID/FID già creati
            if ($fieldType === 'image') {
              $mid = $mediaByBundle['image'][0];
              $node_values[$machine] = [
                'target_id' => $mediaInfo[$mid]['fid'],
                'alt'       => $mediaInfo[$mid]['alt'],
                'title'     => $mediaInfo[$mid]['alt'],
              ];
            } else { // entity_reference -> media
              $node_values[$machine] = ['target_id' => $mediaByBundle['image'][0]];
            }
            continue;
          }

          // Per ogni URL: crea file/media se serve
          $items = [];
          foreach ($urls as $u) {
            $u = $resolveUrl(trim($u));
            if ($u === '') continue;

            if ($fieldType === 'image') {
              // image field -> serve FID + alt/title
              // riusa file da media creato se combacia, altrimenti scarica
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
            } else {
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

        // === TASSONOMIE (entity_reference -> taxonomy_term) ===
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
              if (!isset($termsCache[$vid][$key])) {
                $term = \Drupal\taxonomy\Entity\Term::create(['vid' => $vid, 'name' => trim((string)$label)]);
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

        // === SCALARI (mai url su campi immagine/media) ===
        $value = $firstMatch($xpaths);
        if ($value === NULL) continue;

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
        } else {
          $node_values[$machine] = $value;
        }
      }
      if(!$this->getDryRun()) {
        $node = \Drupal\node\Entity\Node::create($node_values);
        $node->save();
        $node_id = $node->id();
      } else {
        $node_id = $this->t('dry run executed correctly');
      }
      break; // un solo nodo da questo modello
    }

    return $this->t('Content imported with the following id:') . $node_id;
  }

  function fixJson(string $json): string {
    // 0) Rimuove BOM se presente
    $json = preg_replace('/^\xEF\xBB\xBF/', '', $json);

    // 1) Escapa backslash “orfani” (non seguiti da una escape JSON valida)
    //    Valide: \" \\ \/ \b \f \n \r \t \u
    $json = preg_replace('/\\\\(?!["\\\\\/bfnrtu])/', '\\\\', $json);

    // 2) Rimuove virgole finali prima di chiusure di oggetti/array
    $json = preg_replace('/,(\s*[}\]])/', '$1', $json);

    // 3) Collassa doppie o più ] subito prima di una nuova proprietà "..." :
    //    Esempio: "]] , \"notes\"" -> "] , \"notes\""
    $json = preg_replace('/\]{2,}(?=,\s*"[A-Za-z0-9_]+"\s*:)/', ']', $json);
    $json = str_replace('}]}],"notes"', '}],"notes"', $json);

    $json = $this->removeOuterBracketsAndEnsureClosingBrace($json) . '}';
    // Prova a decodificare
    $data = json_decode($json, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
      throw new \Exception('JSON ancora non valido: ' . json_last_error_msg());
    }

    // Riserializza carino e leggibile
    return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
  }


  /**
   * Rimuove la prima '[' e l'ultima ']' dal testo, poi assicura che
   * l'ultimo carattere non-whitespace sia '}' (se non lo è, lo aggiunge).
   * NOTA: opera a livello di stringa, non valida il JSON.
   */
  function removeOuterBracketsAndEnsureClosingBrace(string $json): string
  {
    $len = strlen($json);
    if ($len === 0) {
      return '}';
    }

    // Trova primo e ultimo carattere non-whitespace
    $start = 0;
    while ($start < $len && ctype_space($json[$start])) $start++;

    $end = $len - 1;
    while ($end >= 0 && ctype_space($json[$end])) $end--;

    // Rimuove la prima '[' se presente
    $removedStart = false;
    if ($start <= $end && $json[$start] === '[') {
      $start++;
      $removedStart = true;
    }

    // Rimuove l'ultima ']' se presente
    $removedEnd = false;
    if ($end >= $start && $json[$end] === ']') {
      $end--;
      $removedEnd = true;
    }

    // Ricompone stringa (preservando whitespace esterno)
    $prefix = substr($json, 0, $removedStart ? $start - 1 : $start); // whitespace iniziale
    $inner = substr($json, $start, $end - $start + 1);
    $suffix = substr($json, $removedEnd ? $end + 2 : $end + 1);       // whitespace finale

    $result = $prefix . $inner . $suffix;

    // Se l'ultimo char non-whitespace non è '}', aggiungilo prima dell'eventuale whitespace finale
    if (preg_match('/\s*$/', $result, $m)) {
      $trail = $m[0];                                   // whitespace di coda
      $body = substr($result, 0, strlen($result) - strlen($trail));
      if ($body === '' || substr($body, -1) !== '}') {
        $body .= '}';
      }
      $result = $body . $trail;
    } else {
      // Fallback: nessun match (caso raro), aggiungi semplicemente '}'
      if ($result === '' || substr($result, -1) !== '}') {
        $result .= '}';
      }
    }

    return $result;
  }

// -------- Esempio rapido --------
// $input = "[\n  {\"action\":\"refineModel\",\"model\":{\"foo\":\"bar\"}}\n]\n";
// echo removeOuterBracketsAndEnsureClosingBrace($input);
// Output (ultimo char non-whitespace = '}'):
// {"action":"refineModel","model":{"foo":"bar"}}


  protected function decodeModelPayload($raw): array {
    if(!is_string($raw) && $raw instanceof \Drupal\ai\OperationType\Chat\ChatMessage) {
      $raw = $raw->getText();
    }
    // 1) Normalizza stringa (BOM, code fences, spazi)
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw); // rimuove BOM
    // rimuove code fences ``` e ```json ai bordi
    $raw = preg_replace('/^\s*```(?:json)?\s*|\s*```\s*$/m', '', $raw);
    $raw = trim($raw);

    // 2) Primo tentativo: decodifica diretta
    $decoded = $this->tryJsonDecode($raw);
    if($decoded === null){
      $decoded = $this->fixJson($raw);
      return json_decode($decoded, TRUE);
    }

    return [];
  }

  /** Prova json_decode con flags sicuri; ritorna mixed|null */
  private function tryJsonDecode(string $s) {
    // Preferisce eccezioni se disponibili
    if (defined('JSON_THROW_ON_ERROR')) {
      try {
        return json_decode($s, true, 512, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
      } catch (\Throwable $e) {
        return null;
      }
    }
    $result = json_decode($s, true);
    return (json_last_error() === JSON_ERROR_NONE) ? $result : null;
  }

  /** Estrae il "model" dall’oggetto/array decodificato; ritorna array|null */
  private function unwrapModel($decoded): ?array {
    // Caso 1: già modello "piatto"
    if (is_array($decoded) && isset($decoded['content_types']) || isset($decoded['taxonomies']) || isset($decoded['media_bundles'])) {
      // Se è un array indicizzato che inizia con 'content_types', questo if potrebbe essere vero per errore;
      // ma nella pratica content_types/taxonomies/media_bundles sono chiavi stringa.
      if (isset($decoded['content_types']) || isset($decoded['taxonomies']) || isset($decoded['media_bundles'])) {
        return [
          'content_types'  => $decoded['content_types']  ?? [],
          'taxonomies'     => $decoded['taxonomies']     ?? [],
          'media_bundles'  => $decoded['media_bundles']  ?? [],
        ];
      }
    }

    // Caso 2: wrapper come oggetto { action, model }
    if (is_array($decoded) && isset($decoded['model']) && is_array($decoded['model'])) {
      return [
        'content_types'  => $decoded['model']['content_types']  ?? [],
        'taxonomies'     => $decoded['model']['taxonomies']     ?? [],
        'media_bundles'  => $decoded['model']['media_bundles']  ?? [],
      ];
    }

    // Caso 3: wrapper come array es. [ { action, model }, ... ]
    if (is_array($decoded) && array_keys($decoded) === range(0, count($decoded) - 1) && !empty($decoded)) {
      $first = $decoded[0];
      if (is_array($first) && isset($first['model']) && is_array($first['model'])) {
        return [
          'content_types'  => $first['model']['content_types']  ?? [],
          'taxonomies'     => $first['model']['taxonomies']     ?? [],
          'media_bundles'  => $first['model']['media_bundles']  ?? [],
        ];
      }
      // oppure il primo elemento è già il modello
      if (is_array($first) && (isset($first['content_types']) || isset($first['taxonomies']) || isset($first['media_bundles']))) {
        return [
          'content_types'  => $first['content_types']  ?? [],
          'taxonomies'     => $first['taxonomies']     ?? [],
          'media_bundles'  => $first['media_bundles']  ?? [],
        ];
      }
    }

    return null;
  }

  /**
   * Estrae il primo blocco JSON bilanciato ({...} o [...]) ignorando stringhe e escape.
   * Ritorna la sottostringa JSON oppure null.
   */
  private function extractFirstJsonBlock(string $s): ?string {
    $len = strlen($s);
    $start = -1;
    $stack = [];
    $inString = false;
    $escape = false;

    for ($i = 0; $i < $len; $i++) {
      $ch = $s[$i];

      if ($inString) {
        if ($escape) {
          $escape = false;
        } elseif ($ch === '\\') {
          $escape = true;
        } elseif ($ch === '"') {
          $inString = false;
        }
        continue;
      }

      if ($ch === '"') {
        $inString = true;
        continue;
      }

      if ($ch === '{' || $ch === '[') {
        if ($start === -1) {
          $start = $i;
        }
        $stack[] = ($ch === '{') ? '}' : ']';
        continue;
      }

      if (($ch === '}' || $ch === ']') && !empty($stack)) {
        $expected = array_pop($stack);
        // se non combacia, JSON spezzato: abort
        if ($ch !== $expected) {
          return null;
        }
        if (empty($stack) && $start !== -1) {
          // blocco completo trovato
          return substr($s, $start, $i - $start + 1);
        }
      }
    }
    return null;
  }

  /**
   * @param array $modelToCreate
   * @return TranslatableMarkup
   */
  protected function createQueue(array $modelToCreate){
    $pages = $this->getPages();
    $num = count($pages);
    foreach($pages as $singleurl){
      // Use the Queue API to enqueue a single job
      $queue = \Drupal::service('queue')->get('ai_content_migrate.import_content');
      $queue->createItem([
        'url' => $singleurl,
        'model' => $modelToCreate,
        'queued_at' => \Drupal::time()->getRequestTime(),
      ]);
    }
    return $this->t("created queue of $num elements, run drupal standard cron to execute the migration");
  }


}
