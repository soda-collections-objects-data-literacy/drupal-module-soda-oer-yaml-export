<?php
namespace Drupal\soda_oer_yaml\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

class YamlTabController extends ControllerBase {

  public function yaml(NodeInterface $node) {
    // Only proceed for your target content type.
    if ($node->bundle() !== 'ressource') {
      $this->messenger()->addWarning('YAML export is not available for this content type.');
      return $this->redirect('entity.node.canonical', ['node' => $node->id()]);
    }

    // Build the YAML data.
    $data = [
      '\'@context\'' => "https://schema.org/",
      'type' => "LearningResource",
      'creativeWorkStatus' => 'Published',
      'name' => $node->getTitle(),
      'description' => $node->get('body')->getValue(),
      'license' => "https://creativecommons.org/licenses/by/4.0/deed.de",
      'about' => ["https://w3id.org/kim/hochschulfaechersystematik/n0"],
      "learningResourceType" => "https://w3id.org/kim/hcrt/drill_and_practice",
      "educationalLevel" => ["https://w3id.org/kim/educationalLevel/level_A","https://w3id.org/kim/educationalLevel/level_C"],
      "datePublished" => date('Y-m-d', $node->get('created')->getValue()[0]['value']),
      //"inLanguage" => array_map(fn($x): string => $value['value'],$node->get('field_oer_sprache')->getValue()),
      "inLanguage" => [$node->get('field_oer_sprache')->getValue()[0]['value']],
      
      //'fields' => [],
    ];
    //print_r($node->get('field_oer_sprache')->getValue()[0]['value']);

    // Add your custom fields.
    /*foreach ($node->getFields() as $name => $field) {
      if ($field->access('view')) {
        $data['fields'][$name] = $field->getValue();
      }
    }*/

    // Convert to YAML.
    $yaml = \Symfony\Component\Yaml\Yaml::dump($data, 10, 2);

    // Return the YAML as a response.
    $response = new Response($yaml);
    $response->headers->set('Content-Type', 'text/plain');

    return $response;
  }
  
  private function userData(int $uid) {
    
  }
}
