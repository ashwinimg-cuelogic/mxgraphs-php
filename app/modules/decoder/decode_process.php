<?php
  include_once(BASE_DIR."src/mxServer.php");
  include_once(BASE_DIR. 'app'.$ds.'factories'.$ds.'service.factory.php');
  include_once(BASE_DIR. 'app'.$ds.'modules'.$ds.'decoder'.$ds.'service_configuration.php');
  include_once(BASE_DIR. 'app'.$ds.'modules'.$ds.'decoder'.$ds.'template_model.php');

  // Uses a local font so that all examples work on all platforms. This can be
  // changed to vera on Mac or arial on Windows systems.
  //mxConstants::$DEFAULT_FONTFAMILY = BASE_DIR."/examples/ttf/verah.ttf";
  //mxConstants::$DEFAULT_FONTFAMILY = "C:\WINDOWS\Fonts\arial.ttf";
     
  mxConstants::$DEFAULT_FONTFAMILY = BASE_DIR."app/ttf/verah.ttf";


  class DecodeProcess {
    public $service_factory;
    public $template_model;
    public $graph;
    public $model;
    public $rootCell;
    public $cells;
    public $exclude_services;

    public function __construct() {
      $this->service_factory  = new ServiceFactory();
      $this->template_model   = new TemplateModel(); 
      $this->model            = new mxGraphModel();      
      $this->graph            = new mxGraph($this->model);
      $this->rootCell         = $this->graph->getDefaultParent();
      $this->cells            = array();
      $this->exclude_services = array('securitygroup');
    }
 

    public function autoLayoutFixedDimChildrenofChild($service, $objRC, $graph) {      
      $s_child_index = $objRC["cindex"];
      $servicesList = $service->children;
      $threshold = (ceil(sqrt(count($servicesList))) > $objRC['threshold']) ? ceil(sqrt(count($servicesList))) : $objRC['threshold'];
      $rows = $objRC['rows'];
      $cols = $objRC['cols'];
      $maxlist = array();
      $prevX = 0;
      $prevCell = array();
      $x = 0;

      $maxThresholdInParent = false;    
      
      if (count($service->children) > 0) {
        foreach ($service->children as $key=>$subnet_child)
        {   
          if ($subnet_child->value['additional_properties']['generic_type'] != 'connectors')
          {
            $thr_diff = $s_child_index % $threshold;

            $size_ = strtolower($subnet_child->value['additional_properties']['service_type']) == 'db' ? $subnet_child->geometry->width : $subnet_child->geometry->width + 10; 
            if ($prevCell[$key-1]) {
              $prev_cell_geo =  $prevCell[$key-1]->getGeometry();
              $x =  $this->service_factory->spacing_x + $prev_cell_geo->x + $prev_cell_geo->width;
            } else {
              $x += $this->service_factory->spacing_x;
            }
            
            $space_y = $this->service_factory->spacing_y + $this->service_factory->spacing_y * ($rows - 1);
            $y = $space_y + $size_ * ($rows - 1);

            $_dim = (strtolower($subnet_child->value['additional_properties']['service_type']) == "autoscaling") ? 100 : $subnet_child->geometry->width;
            $y = (strtolower($subnet_child->value['additional_properties']['service_type']) == "autoscaling") ? ($y) : $y;
            $geometry = new mxGeometry($x, $y, $_dim, $_dim);
            $subnet_child->setGeometry($geometry);
            

            //draw rectangle to store the volume count in case of autoscaling configuration
            $volumeCount = 0;
            $autoscalingConfiguration;        
            if (strtolower($subnet_child->value['additional_properties']['service_type']) == "autoscaling")
            {
              foreach($subnet_child->children as $child)
              {              
                if (strtolower($child->value['additional_properties']['service_type']) == "autoscalingconfiguration") 
                {
                  $autoscalingConfiguration = $child;
                  foreach($autoscalingConfiguration->value['properties'] as $property)
                  {
                    if (strtolower($property['name']) ==  'block_device_mappings')
                    {
                      $volumeCount = count($property['value']);               
                    }             
                  }
                  if ($volumeCount > 0)
                  {
                    $volumeVetex = $this->graph->insertVertex(
                      $autoscalingConfiguration,
                      null,
                      (string)$volumeCount,
                      $autoscalingConfiguration->geometry->x+10,// $x + $_dim/4,
                      $autoscalingConfiguration->geometry->y+10,//$y + $_dim/4,
                      25,
                      25,
                      'shape=image;image='.IMAGE_PATH.'/services/compute/server/volume.png;whiteSpace=wrap;overflow=hidden'
                    ); 
                  }     
                }
              }                   
            }

            //draw rectangle to store the volume count in case of server woithin the autoscaling group
            if (strtolower($subnet_child->value['additional_properties']['service_type']) == "server") {
              $volumeCount = 0;
              $volumeChild;
              foreach($subnet_child->children as $volume)
              {
                if (in_array(strtolower($volume->value['additional_properties']['service_type']), ['volume', 'iscsivolume']))
                {
                    $volumeCount++;
                    if (strtolower($volume->value['additional_properties']['service_type']) == "volume")
                    {
                      $volumeChild = $volume;
                    }                 
                }
              }

              $filers = array_filter($subnet_child->value['properties'], function($property) {
                return strtolower($property['name']) == "filer_ids";
              });

              foreach ($filers as $filer)
              {
                $volumeCount = $volumeCount + count($filer['value']);
              }

              if ($volumeCount> 0 && isset($volumeChild))
              {
                $serviceStyle = $this->service_factory->loadStyle($volumeChild->value); 

                $volumeVetex = $this->graph->insertVertex(
                  $subnet_child,
                  null,
                  (String)$volumeCount,
                  $_dim -20,
                  $_dim -20,
                  25,
                  25,
                  $serviceStyle
                );
              }
            }

            $prevCell[] = $subnet_child;

            $x += $size_;

            if ($s_child_index > 0 && $thr_diff == 0)
            {
              $rows += 1;
              $cols = 0;
              $x = 0;
              $maxThresholdInParent = true;
            }

            if ($s_child_index > 0)
            {
              $maxlist[] = array('w'=> $x + $_dim, 'h'=> $y + $_dim);
            }

            $cols += 1;
            $s_child_index += 1;

            if ($cols > $threshold)
            {
              $maxThresholdInParent = true;
              $cols = $objRC['cols'];
              $x = 0;
            }
          }
        };
      }
      return array(
        'rows'      => $rows,
        'cols'      => $maxThresholdInParent ? $threshold : $cols,
        'threshold' => $threshold,
        'cindex'    => $s_child_index,
        'maxlist'   => $maxlist
      );
    }

    public function autoLayoutFixedDimChildren($main_parent_cell, $next_child_pos) {   
      $this->service_factory->saveLog("subnet". count($main_parent_cell->children));
      if (isset($main_parent_cell->children) && count($main_parent_cell->children) > 0) 
      {
          foreach($main_parent_cell->children as $subnet) {
            $s_child_index = 1;
            $threshold = 5;
            $rows = 1;
            $cols = 1;
            $subnet_geo = $subnet->getGeometry();
           
            // Auto arrage subnet children (server / asg's)
            $objRC = $this->autoLayoutFixedDimChildrenofChild($subnet, array(
                'rows'      => $rows,
                'cols'      => $cols,
                'threshold' => $threshold,
                'cindex'    => $s_child_index
            ), $graph);

            $s_child_index = $objRC['cindex'];
            $threshold = $objRC['threshold'];
            $rows = $objRC['rows'];
            $cols = $objRC['cols'];
           
            $dim_ = array();
            if (count($objRC['maxlist']) > 0)
            {             
                $dim_ = $this->service_factory->get_dimemtions_max($objRC['maxlist']);              
                $dim_['h'] = $dim_['h'] + $this->service_factory->spacing_y; //last_child_dim*rows + spacing_x + spacing_y;  
            }
            else
            {
                $dim_['w'] = $this->service_factory->last_child_dim * $cols + $this->service_factory->spacing_x + $this->service_factory->spacing_y;
                $dim_['h'] = $this->service_factory->last_child_dim * $rows + $this->service_factory->spacing_x + $this->service_factory->spacing_y;
            }
            $subnet_geo->width  = $dim_['w'];
            $subnet_geo->height = $dim_['h'];
            $subnet_geo->x      = $next_child_pos['x'];
            $subnet_geo->y      = $next_child_pos['y'];
            $subnet->setGeometry($subnet_geo);

            $pre_max = $next_child_pos['max_'];

            $next_child_pos = array(
                'x'      => 18,
                'y'      => 22 * 2 + $subnet_geo->y + $subnet_geo->height,
                'width'  => $subnet_geo->width,
                'height' => $subnet_geo->height,
                'prev'   => array(
                  'x'      => $subnet_geo->x,
                  'y'      => $subnet_geo->y,
                  'width'  => $subnet_geo->width,
                  'height' => $subnet_geo->height
                )
            );
            $next_child_pos['max_'] = array_merge($pre_max, array(array(
              'w'=> $subnet_geo->x + $subnet_geo->width,
              'h'=> $subnet_geo->y + $subnet_geo->height
            )));
        }
      }
      return $next_child_pos;
    }

    public function graphAutoLayout() {     
      //$this->service_factory->clearLog();

      $allCells = $this->graph->model->cells;
      $vpcs = array_filter($allCells, function($cell) {
        return isset($cell->value) && strtolower($cell->value['additional_properties']['service_type']) === 'vpc';
      });

      foreach($vpcs as $vpc) {
        $azs = array_filter($vpc->children, function($cell) {
         return $cell->value && strtolower($cell->value['additional_properties']['service_type']) === 'availabilityzone';
        });
      

        $threshold_vc = 2;
        $v_rows = 1;
        $v_cols = 1;
        $vpc_child_index = 1;
        $vpc_geo = $vpc->getGeometry();
        
        $vpc_geo->x = 30;
        $vpc_geo->y = 30;
        
        $vpc_next_child_pos = array(
          "x"      => 18,
          "y"      => 22,
          "width"  => 0,
          "height" => 0,
          "max_"   => array(),
          "prev"   => array()
        );

        foreach($azs as $az) {
          $az_next_child_pos = array(
              'x'      => 18,
              'y'      => 22,
              'width'  => 0,
              'height' => 0,
              'prev'   => array(
                'x'      => 18, 
                'y'      => 22, 
                'width'  => 0, 
                'height' => 0
              ),
              'max_'=> array()
          );
          $az_geo = $az->getGeometry();
          $az_next_child_pos = $this->autoLayoutFixedDimChildren($az, $az_next_child_pos, $graph);
          $dim_ = array();
          if (sizeof($az_next_child_pos['max_']) > 1) {
            $dim_ = $this->service_factory->get_dimemtions_max($az_next_child_pos['max_']);
          } else {
            $dim_["w"] = $az_next_child_pos["prev"]["x"] + $az_next_child_pos["prev"]["width"] + $this->service_factory->spacing_x;
            $dim_["h"] = $az_next_child_pos["prev"]["y"] + $az_next_child_pos["prev"]["height"] + $this->service_factory->spacing_y;
          }
          $az_geo->width  = $dim_["w"];
          $az_geo->height = $dim_["h"];
          $az_geo->x      = $vpc_next_child_pos["x"];
          $az_geo->y      = $vpc_next_child_pos["y"];
          $thr_diff       = $vpc_child_index % $threshold_vc;
          $az->setGeometry($az_geo);

          $pre_max = $vpc_next_child_pos["max_"];
          $vpc_next_child_pos = array(
            'x'      => 18,
            'y'      => 22 * 2 + $az_geo->y + $az_geo->height,
            'width'  => $az_geo->width,
            'height' => $az_geo->height,
            'prev'   => $az_geo
          );        

          $vpc_next_child_pos["max_"] = array_merge($pre_max, array(array(
            'w'=> $az_geo->x + $az_geo->width,
            'h'=> $az_geo->y + $az_geo->height
          )));

          if ($vpc_child_index > 0 && $thr_diff == 0)
          {
            $v_rows += 1;
            $v_cols = 0;
          }
          $v_cols += 1;
          $vpc_child_index += 1;
        } // end of looping through azs     

        if (!$vpc_next_child_pos['prev']) {
            $vpc_geo->width = 660;
            $vpc_geo->height = 420;
        } else {
            $get_dim = $this->service_factory->get_dimemtions_max($vpc_next_child_pos["max_"]);          
            $vpc_geo->width = $get_dim["w"] + 350;
            $vpc_geo->height = $get_dim["h"];
        }     
        $vpc->setGeometry($vpc_geo);

        ///////////////////////////////////   VPC OTHER SERVICES   ///////////////////////////////////
        $vpc_other_services = array_filter($vpc->children, function ($c)
        {
          $this->service_factory_obj = new ServiceFactory();
          return ($c->value && !$this->service_factory_obj->isParent($c->value));
        });    

        $geo_new = array(
          'x'      => $vpc_geo->width - 100,
          'y'      => 25,
          'width'  => 56,
          'height' => 56
        );
        foreach($vpc_other_services as $key => $service)
        {
            $geo = $service->getGeometry();
            $geo->width = $geo_new['width'];
            $geo->height = $geo_new['height'];
            $geo->x = $geo_new['x'];
            if (strtolower($service->value['additional_properties']['service_type']) == 'internetgateway')
            {
                $geo->y = 100;
                //to add it at the edge of vpc
                $geo->x = - 25; 
            }
            else
            {
              $geo->y = $geo_new['y'];            
              $geo_new['y'] += 100;
            }

            $service->setGeometry($geo);
           
            $vpc_new_geo = $vpc->getGeometry();
            $vpc_new_geo->height = $vpc_new_geo->height + $geo->height + $this->service_factory->spacing_y;
            $vpc->setGeometry($vpc_new_geo);          
           
        };  

        $vpc_sgrps = array_filter($vpc->children, function ($c)
        {
            return ($c->value && strtolower($c->value['additional_properties']['service_type']) == 'subnetgroup');
        });     

        $sgrp_next_child_pos = array(
          'x'      => $geo_new['x'],
          'y'      => $geo_new['y'] + 100,
          'width'  => 160,
          'height' => 160
        );

        foreach($vpc_sgrps as $service)
        {          
            $s_child_index = 1;
            $threshold = 5;
            $rows = 1;
            $cols = 1;
            $sgrp_geo = $service->getGeometry();
            $objRC = $this->autoLayoutFixedDimChildrenofChild($service, array(
              'rows'      => $rows,
              'cols'      => $cols,
              'threshold' => $threshold,
              'cindex'    => $s_child_index
            ), $graph);

            $s_child_index = $objRC['cindex'];
            $threshold = $objRC['threshold'];
            $rows = $objRC['rows'];
            $cols = $objRC['cols'];

            // TODO: Improve it to have 2 children (subnets) in a row and fix issue of width       
            $sgrp_geo->width = $this->service_factory->last_child_dim * $cols + ($this->service_factory->spacing_x * $cols) + $this->service_factory->spacing_x;
            $sgrp_geo->height = $this->service_factory->last_child_dim * $rows + ($this->service_factory->spacing_y * $rows) + $this->service_factory->spacing_y;
            $sgrp_geo->x = $sgrp_next_child_pos['x'] - $sgrp_geo->width + 80;
            $sgrp_geo->y = $sgrp_next_child_pos['y'];
            $sgrp_next_child_pos['y'] += $sgrp_geo->height + 22;
            $service->setGeometry($sgrp_geo);
            if ($vpc_geo->height < $sgrp_geo->y + $sgrp_geo->height)
            {
                $vpc_geo->height += $sgrp_geo->height + 30;
                $vpc->setGeometry($vpc_geo);
            }

        };
      }

      foreach ($allCells as $c) {        
        if ($c->value && strtolower($c->value['additional_properties']['service_type']) == 'subnet' && $c->getChildCount() > 0) {       
          foreach($c->children as $childCell) {
            if ($childCell && $childCell->value && strtolower($childCell->value['generic_type']) == 'connectors') {
              $this->service_factory->setPortPlacement($c, $childCell);
            }
          }
        }
      }
    }

    public function drawConnectionLines($services) {  
      $connectibleServices = ['loadbalancer', 'server', 'autoscaling', 'routetable', 'subnet', 'networkinterface'];
      $connectedServices = array(
        'loadbalancer'     => ['server', 'autoscaling', 'subnet'], 
        'autoscaling'      => ['loadbalancer'], 
        "networkinterface" => ['server'], 
        'server'           => ['loadbalancer', 'networkinterface'],
        'routetable'       => ['subnet'],
        'subnet'           => ['routetable', 'loadbalancer']
      );
      $allCells = $this->graph->model->cells;
      foreach ($allCells as $key => $cell) {  
       if ($cell->value && $cell->value['generic_type'] !== 'connectors' && in_array(strtolower($cell->value['additional_properties']['service_type']), $connectibleServices)) {
          $service = $cell->value;         
          $externalfacinginterfaces = $this->service_factory->getExternalFacingInterfaces($service['interfaces']);

          $this->service_factory->saveLog(json_encode($externalfacinginterfaces));

          foreach ($externalfacinginterfaces as $intf) {    
            if (in_array(
              strtolower(str_replace('Protocols::' , '', $intf['interface_type'])), 
              $connectedServices[strtolower($cell->value['additional_properties']['service_type'])]
            )) {
              
              foreach($intf['connections'] as $connection) {
                if (isset($connection['type']) && strtolower($connection['type']) === 'implicit') {
                  continue; 
                } 
                
                foreach($this->template_model->findParentServiceByInterfaceId($services, $connection['interface_id']) as $value) {
                  $t1 = $value;
                };
                $this->service_factory->saveLog("connectores t1=");
                $this->service_factory->saveLog($connection['interface_id']);
                $this->service_factory->saveLog($connection['interface_id']);
                $this->service_factory->saveLog(" connectores t2=");
                $this->service_factory->saveLog($connection['remote_interface_id']);
                

                foreach($this->template_model->findParentServiceByInterfaceId($services, $connection['remote_interface_id']) as $value) {
                  $t2 = $value;
                } 
              
               
               if ($t1 && $t2) {
                  $sorceCells = array_filter($this->graph->model->cells, function($modelCell)  use($t1){
                    return $modelCell->value && $modelCell->value['id'] == $t1['id'];
                  });

                  $targetCells = array_filter($this->graph->model->cells, function($modelCell)  use($t2){
                    return $modelCell->value && $modelCell->value['id'] == $t2['id'];
                  });

                  foreach($sorceCells as $sourceCell) {
                    foreach($targetCells as $targetCell) {
                      if (strtolower($targetCell->value['additional_properties']['service_type']) == 'loadbalancer') {
                        $tempCell = $targetCell;
                        $targetCell = $sourceCell;
                        $sourceCell = $tempCell;
                      }

                      $sourceConnectors = array_filter($sourceCell->children, function($childCell) use($targetCell) {
                        return 
                        $childCell->value && 
                        strtolower($childCell->value['generic_type']) == "connectors" &&
                        strtolower($childCell->value['service_type']) == strtolower($targetCell->value['additional_properties']['service_type']);
                      });

                      foreach($sourceConnectors as $s) {
                        $sourceConnector = $s;
                      }

                      $targetConnectors = array_filter($targetCell->children, function($childCell) use($sourceCell) {
                        return 
                        $childCell->value && 
                        strtolower($childCell->value['generic_type']) == "connectors" &&
                        strtolower($childCell->value['service_type']) == strtolower($sourceCell->value['additional_properties']['service_type']);
                      });
                      foreach($targetConnectors as $s) {
                        $targetConnector = $s;
                      }
                      $edge = new mxCell(null, new mxGeometry(), "shape=connector;strokeColor=".$this->service_factory->getStrokeColor($sourceConnector->value['service_type']).";strokeWidth=3;edgeStyle=topToBottomEdgeStyle");

                      $edge->setId($connection['id']);
                      $edge->setEdge(true);
                      $edge->geometry->relative = true;
                      $this->graph->model->add($this->graph->getDefaultParent(), $edge);
                      $this->graph->model->setTerminals($edge, $sourceConnector, $targetConnector);
                    }
                  }
                }
              }
            }
          }
        }
      };
      $serverIGCells = array_filter($this->graph->model->cells, function($cell) {
        return $cell->value && in_array(strtolower($cell->value['additional_properties']['service_type']), ['server', 'internetgateway']);
      });

      if (count($serverIGCells) > 0) {
        foreach ($this->graph->model->cells as $cell) {
          if ($cell->value && strtolower($cell->value['additional_properties']['service_type']) == 'routetable') {

            foreach ($cell->value['properties'] as $prop) {
              if ($prop['name'] == 'routes') {
                
                foreach ($prop['value'] as $route) {                
                  if ($route && (isset($route['connected_service_name']) || (isset($route['gatewayId']) && substr($route['gatewayId'], 0, 4) == "igw-"))) {
                    foreach($serverIGCells as $serviceCell) {
                      $connectionFound = false;
                      if (isset($route['gatewayId']) &&
                        substr($route['gatewayId'], 0, 4) == "igw-" &&
                        strtolower($serviceCell->value['additional_properties']['service_type']) == 'internetgateway') 
                      {
                        $igId = array_filter($serviceCell->value['properties'], function($prop) {
                          return $prop['name'] == 'internet_gateway_id';
                        });
                        
                        if ($igId && $igId[0]['value'] == $route['gatewayId']) {
                          $connectionFound = true;
                        }
                      } else if(isset($route['connected_service_name']) && $serviceCell->value['id'] == $route['connected_service_id']) {
                         $connectionFound = true;
                      }

                      if ($connectionFound) {
                        $sourceConnectors = array_filter($cell->children, function($childcell) use($serviceCell) {
                          return 
                              $childcell->value && 
                              strtolower($childcell->value['generic_type']) == "connectors" &&
                              strtolower($childcell->value['service_type']) == strtolower($serviceCell->value['additional_properties']['service_type']);
                        });                      
                        foreach($sourceConnectors as $s) {
                          $sourceConnector = $s;
                        }

                        $targetConnectors = array_filter($serviceCell->children, function($childcell) use($cell){
                          return 
                              $childcell->value && 
                              strtolower($childcell->value['generic_type']) == "connectors" &&
                              strtolower($childcell->value['service_type']) == strtolower($cell->value['additional_properties']['service_type']);
                        });
                        foreach($targetConnectors as $s) {
                          $targetConnector = $s;
                        }


                        $edge = new mxCell(null, new mxGeometry(), "shape=connector;strokeColor=".$this->service_factory->getStrokeColor($sourceConnector->value['service_type']).";strokeWidth=3;edgeStyle=sideToSideEdgeStyle");

                        $edge->setId($route['connected_service_id']);
                        $edge->setEdge(true);
                        $edge->setVisible(true);
                        $edge->geometry->relative = true;
                        $this->graph->model->add($this->graph->getDefaultParent(), $edge);
                        $this->graph->model->setTerminals($edge, $sourceConnector, $targetConnector);
                      }
                    }
                  }
                }
              }
            }
          }
        }
      }
    }

    public function drawLabels() {
      $allCells = $this->graph->model->cells;
      foreach($allCells as $cell) {     
        if ($cell->value && isset($cell->value['additional_properties']) && !in_array(strtolower($cell->value['additional_properties']['service_type']), ['volume', 'networkinterface', 'iscsivolume']) && $cell->isVisible()) { 

          $label = strlen($cell->value['name']) > 15 ? substr($cell->value['name'], 0, 15). "..." : $cell->value['name']; 

          $cidr_blocks = array_filter($cell->value['properties'], function($prop) {
            return strtolower($prop['name']) == "cidr_block";
          });

          foreach($cidr_blocks as $block) {
            $label .= "(".$block['value'].")";
          }

          $this->graph->insertVertex(
            $cell, null, (string)$label, 35, -4, 0, 0, "shape=label;fillColor=none;fontColor=#b0b0b0;verticalAlign=bottom;fontSize=12"
          );
        }        
      }
    }



    /**
    * Creates the graph on the server side
    **/
    public function decode($jsonString = '') {

      //      $this->service_factory->saveLog("Image processing started for -". isset($templateModel['id']) ? $templateModel['id']: '');

      if ($this->service_factory->isJson($jsonString))
      {
        $templateModel = json_decode($jsonString, true);
      } 
      else 
      {
        echo "its not json"; exit;
      }


      
      $this->model->beginUpdate();
      $vpcCell = array();
      $ind = array();
      $cells_asg_reference = array();
      $vpcSG = array();
      $noGeometry = false;
      
      foreach($templateModel['services'] as $key => $service) { 

        $service = $this->service_factory->setServiceType($service);

        if(isset($service['additional_properties']['generic_type']) && !isset($service['type']))
        {
          $service['type'] = $service['additional_properties']['generic_type'];
        }

        if (
            isset($service['additional_properties']) && 
            isset($service['additional_properties']['service_type']) 
            && !in_array(strtolower($service['additional_properties']['service_type']), $this->exclude_services)            
        ) {         
          $serviceConfiguration = new serviceConfiguration($service, $this->model, $this->graph, $this->rootCell, $this->cells, $vpcCell, $cells_asg_reference, $noGeometry, $vpcSG);  
          $setConfiguration = $serviceConfiguration->setServiceConfiguration();

          /**
          * Set position of current service cell
          */
          try {
          $noGeometry = $serviceConfiguration->setServiceGeometry();
          $serviceConfiguration->setServiceType();
          $serviceConfiguration->drawCell();
          $serviceConfiguration->configureConnectionPort();
          $vpcCell = $serviceConfiguration->afterDrawCell();
          $serviceConfiguration->setCellVisibility($service);
          $this->cells = array_merge($this->cells, $serviceConfiguration->cells);

          $this->graph = $serviceConfiguration->graph;
          $this->model = $serviceConfiguration->model;  
          } catch (Exception $e) {
            $this->model->endUpdate();
            $this->ServiceFactory->saveLog(encode_json($e));
            throw($e);
          }  
        }
      }// end of foreach

     // exit;

      try {

          /* adjust size of cells */
          $this->graphAutoLayout();

          /* draw connection lines */
          $this->drawConnectionLines($templateModel['services']);

          /* draw labels for each cell*/
          $this->drawLabels($graph);


          $this->model->endUpdate();
          $this->graph->view->scale =1;


          $image = $this->graph->createImage(null, "#FFFFFF"); 
        

          header("Content-Type: image/png");  
          echo mxUtils::encodeImage($image); 

       } catch (Exception $e) {
          $this->model->endUpdate();
          $this->ServiceFactory->saveLog(encode_json($e));
          throw($e);
        }    
       
    }
}

?>
