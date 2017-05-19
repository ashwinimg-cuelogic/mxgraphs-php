<?php

include_once(BASE_DIR. 'app/factories/service.factory.php');
include_once(BASE_DIR."src/model/mxGeometry.php");

class serviceConfiguration {
  public $service, $model, $graph, $rootCell, $cells, $vpcCell, $cells_asg_reference, $noGeometry, $vpcSG,
  $serviceIdAndParentCell, $geometry, $geo;

  public function __construct($service, $model, $graph, $rootCell, $cells, $vpcCell, $cells_asg_reference, $noGeometry, $vpcSG) {
    $this->service             = $service;
    $this->model               = $model;
    $this->graph               = $graph;
    $this->rootCell            = $rootCell;
    $this->cells               = $cells;
    $this->vpcCell             = $vpcCell;
    $this->cells_asg_reference = $cells_asg_reference;
    $this->noGeometry          = $noGeometry;
    $this->vpcSG               = $vpcSG;
    $this->serviceFactory      = new ServiceFactory();
  }
 

  public function setServiceConfiguration() {
    $this->serviceIdAndParentCell = $this->getServiceIdAndParentCell();       
  }

  public function getCellIfExist($cell_id) {
    if (isset($cell_id) && isset($this->cells[$cell_id])) {
      return $this->cells[$cell_id];
    }     
    else {
      return (strtolower($this->service['additional_properties']['service_type']) === "vpc") ? $this->rootCell : $this->vpcCell;
    }
  }

  /* get service Id and service parent cell, default is vpc
  */  
  private function getServiceIdAndParentCell() {    
    //$this->serviceFactory->saveLog("****************type=".$this->service["additional_properties"]['service_type'] ." service=".$this->service["id"]." parent_id = " . $this->service['additional_properties']['parent_id']);    
    if (isset($this->service['additional_properties']['parent_id']) && is_array($this->service['additional_properties']['parent_id'])) {
      foreach($this->service['additional_properties']['parent_id'] as $parent) {
        $parentcell[] = $this->getCellIfExist($parent);
      }
    } else {
       $parentcell[] = $this->getCellIfExist($this->service['additional_properties']['parent_id']);
       $noOfRepitition = $this->preg_array_key_exists('/^'.$this->service['additional_properties']['parent_id'].'-(\d+)/', $this->cells);       

      if(isset($this->service['additional_properties']['parent_id']) &&  $noOfRepitition> 0) {
        for($i=1; $i<=$noOfRepitition; $i++ ) {
          $parentcell[] = $this->getCellIfExist($this->service['additional_properties']['parent_id']."-".$i);
        }        
      }       
    }
   
    return array(
      "service_id" => $this->service['id'],
      "parentcell" => $parentcell
    );
  }

  public function setServiceGeometry() {     
    $this->noGeometry = (!isset($this->service['geometry'])) ? true : false;
    if ($this->noGeometry) {
      $service_dimentions = $this->serviceFactory->setDimentions($this->service);     
      $this->geo = Array(
        "x" => 24,
        "y" => 24,
        "width" => $service_dimentions['w'],
        "height" => $service_dimentions['h']
      );      
    } else {
       $this->geo = $this->service['geometry'];
    }
    $this->geometry = new mxGeometry($this->geo['x'], $this->geo['y'], $this->geo['width'], $this->geo['height']);
    return $this->noGeometry;
  }

  public function configureConnectionPort() {
    if (!in_array( $this->service['additional_properties']['service_type'], ['server', 'subnet', 'autoscaling']))
    {
        $this->cells[$this->service['id']]->setConnectable(false);
    }    
    $this->serviceFactory->addConnectorPorts($this->graph, $this->cells[$this->service['id']]);
  }

  public function preg_array_key_exists($pattern, $array) {
    $keys = array_keys($array); 
    $return =  (int) preg_grep($pattern, $keys, 0);
    return $return;
  }

  public function drawCell() {    
    //hide the notices and warnings, show only fatel errors
    error_reporting(1);  
    try {
      $serviceStyle = $this->serviceFactory->loadStyle($this->service); 
      foreach($this->serviceIdAndParentCell['parentcell'] as $parentCell) {
        $cell_key_val = $this->service['id'];
        if(array_key_exists($this->service['id'], $this->cells)) {
          /*check whether already cell is renamed 
           if yes how many times
          and then decide new name
          */
          $noOfRepitition = $this->preg_array_key_exists("/^".$this->service['id']."-(\d+)/", $this->cells);
          $cell_key_val = $this->service['id']."-".($noOfRepitition+1);
        }        
        $this->cells[$cell_key_val] = $this->graph->insertVertex(
          $parentCell,
          null,
          $this->service,
          $this->geo['x'],
          $this->geo['y'],
          $this->geo['width'],
          $this->geo['height'],
          $serviceStyle
        );        
      }     
    } catch(Exception $e) {
      echo 'Caught exception: ',  $e->getMessage(), "\n";
    }   
  }

  public function afterDrawCell() {
    if (strtolower($this->service['additional_properties']['service_type']) === "vpc") {
      $this->vpcCell = $this->cells[$this->service['id']];
    }
    return $this->vpcCell;
  }

  public function setCellVisibility() {   
    if (($this->service['additional_properties']['drawable'] == false ||  $this->service['additional_properties']['service_type'] === "volume") && $this->cells[$this->service['id']]) {
      $this->cells[$this->service['id']]->setVisible(false);
    }     
  }

  public function setServiceType() {
    if (
      isset($this->service['type']) && 
      strpos($this->service['type'], 'RouteTable') !== -1 && 
      array_filter($this->service['properties'], function($prop
    ) {
      return $prop['name'] === "main" && $prop['value'] == true;
    })) {
      $this->service['additional_properties']['service_type'] = 'routetable';
    }
  }
}
?>