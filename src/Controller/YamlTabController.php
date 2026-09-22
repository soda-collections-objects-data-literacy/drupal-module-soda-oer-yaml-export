<?php
namespace Drupal\soda_oer_yaml\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;
use ZipArchive;

class YamlTabController extends ControllerBase {

  protected $nodeStorage;

  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->nodeStorage = $container->get('entity_type.manager')->getStorage('node');
    return $instance;
  }

  public function yaml(NodeInterface $node) {
    // Only proceed for your target content type.
    if ($node->bundle() !== 'ressource') {
      $this->messenger()->addWarning('YAML export is not available for this content type.');
      return $this->redirect('entity.node.canonical', ['node' => $node->id()]);
    }

    $yaml = $this->generateNodeYaml($node);

    // Return the YAML as a response.
    $response = new Response($yaml);
    $response->headers->set('Content-Type', 'text/plain');

    return $response;
  }
  
  private function userData(int $uid) {
    
  }

  /**
   * Sanitize a string to be used as a filename, allowing only letters, numbers, hyphens, and underscores.
   */
  private function sanitizeFilename($string) {
    // Replace any character that is not alphanumeric, hyphen, or underscore with an underscore.
    return preg_replace('/[^a-zA-Z0-9\-_]/', '_', $string);
  }

  /**
   * Download CSV file with the same data as YAML export.
   */
  public function downloadCsv() {
    // Load all published 'ressource' nodes where field_format is not empty.
    $nodes = fetchNodes();
    
    if (empty($nodes)) {
      $this->messenger()->addWarning('No resources to export.');
      return $this->redirect('system.admin_content');
    }

    $output = $this->generateCsvContent($nodes);
    
    $response = new Response($output);
    $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
    $response->headers->set('Content-Disposition', 'attachment; filename="oer_export_' . date('Y-m-d_His') . '.csv"');
    $response->headers->set('Pragma', 'public');
    $response->headers->set('Cache-Control', 'must-revalidate, post-check=0, pre-check=0');
    $response->headers->set('Expires', '0');

    return $response;
  }

  /**
   * Generate CSV content for multiple nodes.
   */
  private function generateCsvContent(array $nodes): string {
    $output = "\xEF\xBB\xBF"; // UTF-8 BOM
    
    // Add header row
    $headers = [
      'Authors',
      'License',
      'Link',
      'Title',
      'Community',
      'Description',
      'Discipline',
      'FileFormat',
      'Keywords',
      'Language',
      'LearningResourceType',
      'ProficiencyLevel',
      'PublicationDate',
      'TargetGroup',
    ];
    $output .= implode(',', $headers) . "\r\n";
    
    foreach ($nodes as $node) {
      $row = $this->generateCsvRow($node);
      $output .= implode(',', $row) . "\r\n";
    }
    
    return $output;
  }

  /**
   * Extract data from a node into a structured array.
   * This method is used by both YAML and CSV generation.
   */
  private function extractNodeData(NodeInterface $node): array {
    $body_value = $node->get('body')->value;
    
    // Tags
    $tag_names = [];
    foreach ($node->field_tags ?? [] as $item) {
      if ($term = $item->entity) {
        $tag_names[] = $term->getName();
      }
    }
    
    // OERSI material formats (LearningResourceType)
    $oersi_material_formats = [];
    foreach ($node->field_oersi_materialart ?? [] as $item) {
      if ($term = $item->entity) {
        $oersi_material_formats[] = $term->get('field_uri')->getValue()[0]['value'] ?? '';
      }
    }
    
    // Creators/ Authors
    $creators = [];
    $author_names = [];
    foreach ($node->field_autor_innen ?? [] as $item) {
      if ($user = $item->entity) {
        $user_institution = $user->get('field_oer_autor_institution')->get(0)->view(['type' => 'list_default'])['#markup'] ?? '';
        $user_givenname = $user->get('field_oersi_autor_vorname')->getValue()[0]['value'] ?? '';
        $user_familyname = $user->get('field_oersi_autor_nachname')->getValue()[0]['value'] ?? '';
        $user_orcid = $user->get('field_oersi_autor_orcid')->getValue()[0]['value'] ?? '';
        
        // For YAML
        $creators[] = [
          "givenName" => $user_givenname, 
          "familyName" => $user_familyname, 
          "id" => $user_orcid, 
          "type" => "Person",
          "affiliation" => [
            "name" => $user_institution,
            "id" => "https://ror.org/00f7hpc57",
            "type" => "Organization"
          ]
        ];
        
        // For CSV
        if ($user_givenname || $user_familyname) {
          $author_names[] = trim("$user_givenname $user_familyname");
        }
      }
    }
    
    // Add SODa organization
    $creators[] = [
      "name" => "SODa - Sammlungen, Objekte, Datenkompetenzen",
      "type" => "Organization"
    ];
    $author_names[] = 'SODa - Sammlungen, Objekte, Datenkompetenzen';
    
    $about = ["https://w3id.org/kim/hochschulfaechersystematik/n0"];
    
    // FileFormat
    $file_formats = [];
    if (!$node->get('field_format')->isEmpty()) {
      foreach ($node->field_format as $item) {
        if ($term = $item->entity) {
          $file_formats[] = $term->getName();
        }
      }
    }
    
    // Language - with default
    $languages = [];
    if (!$node->get('field_oer_sprache')->isEmpty()) {
      $lang_values = $node->get('field_oer_sprache')->getValue();
      foreach ($lang_values as $value) {
        if (!empty($value['value'])) {
          $languages[] = $value['value'];
        }
      }
    }
    if (empty($languages)) {
      $languages = ['de'];
    }
    
    $educational_levels = ["https://w3id.org/kim/educationalLevel/level_A", "https://w3id.org/kim/educationalLevel/level_C"];
    
    // Publication Date
    $datePublished = '';
    $changed = $node->get('changed')->getValue();
    $created = $node->get('created')->getValue();
    if (!empty($changed[0]['value'])) {
      $datePublished = date('Y-m-d', $changed[0]['value']);
    } elseif (!empty($created[0]['value'])) {
      $datePublished = date('Y-m-d', $created[0]['value']);
    }
    
    // Link (id)
    $id = '';
    if (!$node->get('field_externer_link')->isEmpty()) {
      $id = $node->get('field_externer_link')->getValue()[0]['uri'] ?? '';
    }
    
    // Description
    $description = '';
    if (!empty($body_value)) {
      $description = strip_tags($body_value);
      $description = str_replace('&nbsp;', ' ', $description);
      $description = str_replace("\r\n", "\n", $description);
      $description = str_replace("\r", "\n", $description);
    }
    
    // Image URL
    $image_url = '';
    if (!$node->get('field_newsimage')->isEmpty()) {
      $media = $node->get('field_newsimage')->entity;
      if ($media && !$media->get('field_media_image')->isEmpty()) {
        $file = $media->get('field_media_image')->entity;
        if ($file) {
          $image_url = \Drupal::service('file_url_generator')->generateAbsoluteString($file->getFileUri());
        }
      }
    }
    
    // Community
    $community[] = "SODa - Sammlungen, Objekte, Datenkompetenzen (S)";
    
    // TargetGroup
    $target_group[] = ["student (BA)", "student (MA)", "student (PhD)", 
                      "data steward", "teacher (school)", 
                      "teacher (higher education)", "researcher"];
    
    $license = 'https://creativecommons.org/licenses/by/4.0/deed.de';
    
    
    return [
      'body_value' => $body_value,
      'title' => $node->getTitle(),
      'tag_names' => $tag_names,
      'oersi_material_formats' => $oersi_material_formats,
      'creators' => $creators,
      'author_names' => $author_names,
      'about' => $about,
      'file_formats' => $file_formats,
      'languages' => $languages,
      'educational_levels' => $educational_levels,
      'datePublished' => $datePublished,
      'id' => $id,
      'description' => $description,
      'image_url' => $image_url,
      'community' => $community,
      'target_group' => $target_group,
      'license' => $license,
    ];
  }

  /**
   * Generate a single CSV row for a node.
   */
  private function generateCsvRow(NodeInterface $node): array {
    $data = $this->extractNodeData($node);
    
    // Helper function to join array with asterisk
    $joinMulti = function(array $items): string {
      return implode(' * ', $items);
    };
    
    // Build the row, escaping commas and quotes
    $escapeCsv = function(string $value): string {
      if (strpos($value, ',') !== false || strpos($value, '"') !== false || strpos($value, '\n') !== false) {
        return '"' . str_replace('"', '""', $value) . '"';
      }
      return $value;
    };
    
    return [
      $escapeCsv($joinMulti($data['author_names'])),
      $escapeCsv($data['license']),
      $escapeCsv($data['id']),
      $escapeCsv($data['title']),
      $escapeCsv($joinMulti($data['community'])),
      $escapeCsv($data['description']),
      $escapeCsv($joinMulti($data['about'])),
      $escapeCsv($joinMulti($data['file_formats'])),
      $escapeCsv($joinMulti($data['tag_names'])),
      $escapeCsv($joinMulti($data['languages'])),
      $escapeCsv($joinMulti($data['oersi_material_formats'])),
      $escapeCsv($joinMulti($data['educational_levels'])),
      $escapeCsv($data['datePublished']),
      $escapeCsv($joinMulti($data['target_group'])),
    ];
  }

  /**
   * Download all ressource nodes as YAML files in a zip archive.
   */
  public function downloadAllYaml() {
    $temp_file = tempnam(sys_get_temp_dir(), 'yaml_zip_');
    $zip = new ZipArchive();
    
    if ($zip->open($temp_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
      $this->messenger()->addError('Could not create zip archive.');
      return $this->redirect('system.admin_content');
    }

    $nodes = fetchNodes();
    
    $includeEntries = [];
    foreach ($nodes as $node) {
      $yaml = $this->generateNodeYaml($node);
      $filename = 'oer_' . $node->id() . '_' . $this->sanitizeFilename($node->getTitle()) . '.yml';
      $subpath = 'oer_metadata/' . $filename;
      $zip->addFromString($subpath, $yaml);
      $includeEntries[] = '- !include oer_metadata/' . $filename;
    }

    // Add metadata.yml with include directives
    if (!empty($includeEntries)) {
      $metadataContent = implode("\n", $includeEntries) . "\n";
      $zip->addFromString('metadata.yml', $metadataContent);
    }

    $zip->close();

    if (!file_exists($temp_file) || filesize($temp_file) === 0) {
      $this->messenger()->addWarning('No YAML files to download.');
      return $this->redirect('system.admin_content');
    }

    $response = new Response(file_get_contents($temp_file));
    $response->headers->set('Content-Type', 'application/zip');
    $response->headers->set('Content-Disposition', 'attachment; filename="oer_yaml_export_' . date('Y-m-d_His') . '.zip"');
    $response->headers->set('Content-Length', filesize($temp_file));
    $response->headers->set('Pragma', 'public');
    $response->headers->set('Cache-Control', 'must-revalidate, post-check=0, pre-check=0');
    $response->headers->set('Expires', '0');

    // Clean up temp file.
    unlink($temp_file);

    return $response;
  }
  
  /**
  * Get all relevant nodes (Knowledge Items) to be published
  */
  private function fetchNodes() {
    // Load all published 'ressource' nodes where field_format is not empty.
    $nids = $this->nodeStorage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'ressource')
      ->condition('status', NodeInterface::PUBLISHED)
      ->exists('field_format')
      ->condition('field_auf_oersi_publizieren', TRUE)
      ->execute();

    $nodes = $this->nodeStorage->loadMultiple($nids);
    return $nodes;
  }

  /**
   * Remove empty values (empty strings, empty arrays/dicts, null) from array recursively.
   */
  private function filterEmptyValues(array $data): array {
    foreach ($data as $key => $value) {
      if (is_array($value)) {
        $data[$key] = $this->filterEmptyValues($value);
        if ($data[$key] === []) {
          unset($data[$key]);
        }
      } elseif ($value === '' || $value === null) {
        unset($data[$key]);
      }
    }
    return $data;
  }

  /**
   * Generate YAML content for a single node.
   */
  private function generateNodeYaml(NodeInterface $node) {
    $data = $this->extractNodeData($node);
    
    # Build description - word-wrap at 80 characters
    $description = wordwrap($data['description'], 80, "\n");
    
    $yaml_data = [
      '@context' => "https://schema.org/",
      'creativeWorkStatus' => 'Published',
      'type' => "LearningResource",
      'name' => $data['title'],
      'description' => $description,
      'license' => $data['license'],
      'id' => $data['id'],
      'creator' => $data['creators'],
      'inLanguage' => $data['languages'],
      'about' => $data['about'],
      'image' => $data['image_url'],
      'learningResourceType' => $data['oersi_material_formats'],
      'educationalLevel' => $data['educational_levels'],
      'datePublished' => $data['datePublished'],
    ];
    
    if (!empty($data['tag_names'])) {
      $yaml_data['keywords'] = $data['tag_names'];
    }

    # Remove empty values (empty strings, empty arrays, null)
    $yaml_data = $this->filterEmptyValues($yaml_data);

    $yaml = \Symfony\Component\Yaml\Yaml::dump($yaml_data, 10, 2, \Symfony\Component\Yaml\Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
    
    # Post-process to convert literal blocks to folded blocks for description and id
    # Symfony Yaml outputs "description: |\n  text..." or "description: |-\n  text..."
    # We want to convert to "description: >-\n  text..."
    $yaml = preg_replace('/^(description:)\s*\|-\s*\n/m', '$1 >-' . "\n", $yaml);
    $yaml = preg_replace('/^(description:)\s*\|\s*\n/m', '$1 >-' . "\n", $yaml);
    $yaml = preg_replace('/^(id:)\s*\|-\s*\n/m', '$1 >-' . "\n", $yaml);
    $yaml = preg_replace('/^(id:)\s*\|\s*\n/m', '$1 >-' . "\n", $yaml);
    
    return $yaml;
  }
}
