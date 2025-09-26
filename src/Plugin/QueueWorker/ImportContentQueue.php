<?php

namespace Drupal\ai_content_migrate\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use GuzzleHttp\ClientInterface;

/**
 * @QueueWorker(
 *   id = "ai_content_migrate.import_content",
 *   title = @Translation("Import content from URL (AI Content Migrate)"),
 *   cron = {"time" = 60}
 * )
 */
class ImportContentQueue extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Service that performs the actual import.
   *
   * @var \Drupal\ai_content_migrate\Importer
   */
  protected $importer;

  /**
   * HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->importer = $container->get('ai_content_migrate.importer');
    $instance->httpClient = $container->get('http_client');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    // Validate payload shape.
    if (empty($data['url']) || !is_string($data['url'])) {
      // Nothing to process; consider logging.
      return;
    }

    $url = $data['url'];

    // Download HTML from the provided URL.
    try {
      $response = $this->httpClient->request('GET', $url, [
        'headers' => [
          'User-Agent' => 'Drupal Crawler/1.0',
          'Accept' => 'text/html',
        ],
        'timeout' => 20,
      ]);
      $html = (string) $response->getBody();
    }
    catch (\Throwable $e) {
      // Log the error and rethrow to allow a retry depending on the runner.
      \Drupal::logger('ai_content_migrate')->error('Failed to fetch HTML for @url: @msg', [
        '@url' => $url,
        '@msg' => $e->getMessage(),
      ]);
      throw $e;
    }


    $node_id = $this->importer->importContent($html, $data['model'] ?? []);
    \Drupal::logger('ai_content_migrate')->info("$node_id correctly imported");

   // $this->importer->importContent($html, $url, is_array($data) ? $data : []);
  }

}
