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
   * Download all ressource nodes as YAML files in a zip archive.
   */
  public function downloadAllYaml() {
    $temp_file = tempnam(sys_get_temp_dir(), 'yaml_zip_');
    $zip = new ZipArchive();
    
    if ($zip->open($temp_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
      $this->messenger()->addError('Could not create zip archive.');
      return $this->redirect('system.admin_content');
    }

    // Load all published 'ressource' nodes where field_format is not empty.
    $nids = $this->nodeStorage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'ressource')
      ->condition('status', NodeInterface::PUBLISHED)
      ->exists('field_format')
      ->condition('field_auf_oersi_publizieren', TRUE)
      ->execute();

    $nodes = $this->nodeStorage->loadMultiple($nids);
    
    $includeEntries = [];
    foreach ($nodes as $node) {
      $yaml = $this->generateNodeYaml($node);
      $filename = 'oer_' . $node->id() . '_' . str_replace(' ', '_', $node->getTitle()) . '.yml';
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
   * Generate YAML content for a single node.
   */
  private function generateNodeYaml(NodeInterface $node) {
    
    # Body
    $processed_body = $node->get('body')->processed;
    
    # Tags
    $tag_names = [];
    foreach ($node->field_tags as $item) {
      if ($term = $item->entity) {
        $tag_names[] = $term->getName();
      }
    }
    
    # OERSI format
    $oersi_material_formats = [];
    foreach ($node->field_oersi_materialart as $item) {
      if ($term = $item->entity) {
        $oersi_material_formats[] = $term->get('field_uri')->getValue()[0]['value'];
      }
    }
    
    # creators
    $creators = [];
    $creators[] = ["name" => "SODa - Sammlungen, Objekte, Datenkompetenzen", "type" => "Organization"];
    foreach ($node->field_autor_innen as $item) {
      if ($user = $item->entity) {
        #$username = $user->get('name')->getValue()[0]['value'];
        $userid = $user->id;
        #if ($user_institution = $user->get('field_oer_autor_institution')->getValue()[0]->entity){
        $user_institution = $user->get('field_oer_autor_institution')->get(0)->view(['type' => 'list_default'])['#markup'];
        
        #$user_institution = $node->get('field_oer_autor_institution')->getSetting('allowed_values')[$user_institution_val];
        
        $user_givenname = $user->get('field_oersi_autor_vorname')->getValue()[0]['value'];
        $user_familyname = $user->get('field_oersi_autor_nachname')->getValue()[0]['value'];
        $user_orcid = $user->get('field_oersi_autor_orcid')->getValue()[0]['value'];
        
        
        $creators[] = array("givenName" => $user_givenname, 
                          "familyName" => $user_familyname, 
                          "id" => $user_orcid, 
                          "type" => "Person",
                          "affiliation" => array("name" => $user_institution,"type" => "Organization"));
      }
    }
    
    # image
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
    
    $data = [
      '@context' => "https://schema.org/",
      'type' => "LearningResource",
      'creativeWorkStatus' => 'Published',
      'name' => $node->getTitle(),
      'description' => strip_tags($processed_body),
      'license' => "https://creativecommons.org/licenses/by/4.0/deed.de",
      'about' => ["https://w3id.org/kim/hochschulfaechersystematik/n0"],
      "learningResourceType" => "https://w3id.org/kim/hcrt/drill_and_practice",
      "educationalLevel" => ["https://w3id.org/kim/educationalLevel/level_A","https://w3id.org/kim/educationalLevel/level_C"],
      "datePublished" => date('Y-m-d', $node->get('created')->getValue()[0]['value']),
      "inLanguage" => [$node->get('field_oer_sprache')->getValue()[0]['value']],
      "id" => $node->get('field_externer_link')->getValue()[0]['uri'],
      "keywords" => $tag_names,
      "learningResourceType" => $oersi_material_formats,
      "creator" => $creators,
      "image" => $image_url
    ];

    return \Symfony\Component\Yaml\Yaml::dump($data, 10, 2);
  }
}
