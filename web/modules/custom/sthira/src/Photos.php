<?php

namespace Drupal\sthira;

use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\Entity\File;

/**
 * Construction photos for the demo (free under the Unsplash License).
 *
 * Downloaded once, when the module is installed, into public://infra-radar/,
 * so the site never depends on Unsplash while it is being shown. If a download
 * fails, the homepage falls back to a drawn skyline.
 * Retry with: drush sthira:photos
 */
final class Photos {

  /**
   * Unsplash's download endpoint (redirects to the image CDN).
   */
  const SOURCE = 'https://unsplash.com/photos/%s/download?force=true&w=1600';

  /**
   * Unsplash's image CDN (official way to use Unsplash photos on a site).
   */
  const CDN = 'https://images.unsplash.com/%s?auto=format&fit=crop&w=1920&q=75';

  /**
   * One construction photo per demo project: project code => [Unsplash
   * photo page id, CDN image id].
   */
  const PROJECTS = [
    // London construction cranes over high-rise work.
    'P-101' => ['tB0qtsE_iKM', 'photo-1670859255584-8b162775407f'],
    // Tower crane between buildings under construction, aerial.
    'P-102' => ['H5n45elIVZQ', 'photo-1541888660500-cce0122eadeb'],
    // Crane on a building under construction, Bergen.
    'P-103' => ['LLKyUGcsEBo', 'photo-1686309736315-26df11a3c51c'],
  ];

  /**
   * Direct image URL for a project's photo (used until a local copy exists).
   */
  public static function remote(string $code): ?string {
    return isset(self::PROJECTS[$code]) ? sprintf(self::CDN, self::PROJECTS[$code][1]) : NULL;
  }

  /**
   * Downloads one photo. Returns its public:// URI, or NULL.
   */
  public static function fetch(string $id, ?string $cdn = NULL): ?string {
    $fs = \Drupal::service('file_system');
    $dir = 'public://infra-radar';
    $fs->prepareDirectory($dir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    $uri = $dir . '/' . preg_replace('/[^A-Za-z0-9_-]/', '', $id) . '.jpg';
    if (file_exists($uri) && filesize($uri) > 10000) {
      return $uri;
    }
    $test = \Drupal::state()->get('sthira.photo_source');
    $urls = $test ? [sprintf($test, $id)] : array_filter([$cdn ? sprintf(self::CDN, $cdn) : NULL, sprintf(self::SOURCE, $id)]);
    $body = NULL;
    foreach ($urls as $url) {
      try {
        $res = \Drupal::httpClient()->request('GET', $url, ['timeout' => 30, 'connect_timeout' => 6, 'allow_redirects' => ['max' => 5]]);
        $data = (string) $res->getBody();
        if (str_starts_with($res->getHeaderLine('Content-Type'), 'image/') && strlen($data) > 10000) {
          $body = $data;
          break;
        }
      }
      catch (\Throwable $e) {
        \Drupal::logger('sthira')->notice('Could not download photo @id from @url: @msg', ['@id' => $id, '@url' => $url, '@msg' => $e->getMessage()]);
      }
    }
    if ($body === NULL) {
      return NULL;
    }
    try {
      $fs->saveData($body, $uri, FileExists::Replace);
      // Keep pages fast: shrink anything larger than needed.
      $image = \Drupal::service('image.factory')->get($uri);
      if ($image->isValid() && $image->getWidth() > 1800) {
        $image->scale(1600);
        $image->save();
      }
      return $uri;
    }
    catch (\Throwable $e) {
      \Drupal::logger('sthira')->notice('Could not save photo @id: @msg', ['@id' => $id, '@msg' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * Downloads the banner photo and gives each demo project its photo.
   *
   * @return int
   *   How many photos are in place.
   */
  public static function seed(): int {
    $done = 0;
    \Drupal::state()->set('sthira.photos_tried', time());
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    foreach (self::PROJECTS as $code => [$id, $cdn]) {
      $nodes = $storage->loadByProperties(['type' => 'project', 'field_code' => $code]);
      $node = reset($nodes);
      if (!$node) {
        continue;
      }
      if (!$node->get('field_photo')->isEmpty()) {
        $done++;
        continue;
      }
      if ($uri = self::fetch($id, $cdn)) {
        // Reuse the file record from an earlier install, so Drupal's cleanup
        // of unused files never removes a photo that is in use again.
        $existing = \Drupal::entityTypeManager()->getStorage('file')->loadByProperties(['uri' => $uri]);
        $file = reset($existing) ?: File::create(['uri' => $uri, 'uid' => 1]);
        $file->setPermanent();
        $file->save();
        $node->set('field_photo', ['target_id' => $file->id(), 'alt' => $node->label()]);
        $node->save();
        $done++;
      }
    }
    return $done;
  }

  /**
   * Fetches missing photos after the current page is sent (at most every
   * 15 minutes), so a site that was not reinstalled still gets them.
   */
  public static function healLater(array $projects): void {
    $missing = array_filter($projects, fn($p) => empty($p['photo']) && isset(self::PROJECTS[$p['code']]));
    if (!$missing || \Drupal::state()->get('sthira.photos_tried', 0) > time() - 900) {
      return;
    }
    \Drupal::state()->set('sthira.photos_tried', time());
    drupal_register_shutdown_function([self::class, 'seed']);
  }

}
