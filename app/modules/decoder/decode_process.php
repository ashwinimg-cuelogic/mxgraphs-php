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
          $externalfacinginterfaces = $this->service_factory->getExternalFacingInterfaces($service['additional_properties']['interfaces']);

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



      $jsonString = '{
        "name": "vpc-d10bdbb5",
        "state": "pending",
        "adapter_id": "906b379e-3f78-4ade-bc64-587a8919bd78",
        "region_id": "b0459d7c-ea81-49b2-ad43-1dbb2d82ac02",
        "created_at": "2016-11-14 07:39 UTC",
        "updated_at": "2016-11-14 07:39 UTC",
        "adapter_type": "AWS",
        "adapter_name": "pratik-067",
        "region_name": "Asia Pacific (Singapore)",
        "shared_with": [],
        "tags_map": {
          "Name": [
            "dasd",
            "atit-subnet2",
            "default",
            "atit-public-sec-gorup",
            "atit-AWS-Linux",
            "default",
            "atit-public-route",
            "atit-igw",
            "atit-VPC",
            "default",
            "atit-DB",
            "SN-V20-DEV-01",
            "main",
            "atit-subnet1",
            "atit-private-sec-group",
            "default"
          ]
        },
        "services": [        
          {
            "id": "efae9156-85de-4e2f-a4d7-167351a887d7",
            "name": "default",
            "type": "Services::Network::SecurityGroup::AWS",
            "geometry": {
              "x": "24",
              "y": "24",
              "width": "56",
              "height": "56"
            },
            "additional_properties": {
              "parent_id": "5fdc0cf0-3dac-4074-a1d8-c811d3891314",
              "depends": [],
              "draggable": false,
              "drawable": false,
              "edge": false,
              "generic_type": "Services::Network::SecurityGroup",
              "id": "efae9156-85de-4e2f-a4d7-167351a887d7",
              "interfaces": [
                {
                  "id": "a6a6d2e4-077c-4ad0-aa54-9af0785c941e",
                  "service_id": "efae9156-85de-4e2f-a4d7-167351a887d7",
                  "name": "default",
                  "interface_type": "Protocols::SecurityGroup",
                  "created_at": "2016-11-10T10:22:50.492Z",
                  "updated_at": "2016-11-10T10:22:50.492Z",
                  "depends": false,
                  "connections": []
                },
                {
                  "id": "8adc2be2-ff89-453e-bb32-4ba9c37d5e95",
                  "service_id": "efae9156-85de-4e2f-a4d7-167351a887d7",
                  "name": null,
                  "interface_type": "Protocols::Vpc",
                  "created_at": "2016-11-10T10:22:53.950Z",
                  "updated_at": "2016-11-10T10:22:53.950Z",
                  "depends": true,
                  "connections": [
                    {
                      "id": "7fc6cc01-2374-47fc-b442-829241fe772f",
                      "interface_id": "8adc2be2-ff89-453e-bb32-4ba9c37d5e95",
                      "remote_interface_id": "30f34d56-7767-4f6e-b3d9-e138fc5d21e5",
                      "created_at": "2016-11-10T10:22:53.966Z",
                      "updated_at": "2016-11-10T10:22:53.966Z",
                      "internal": false
                    }
                  ]
                }
              ],
              "name": "default",
              "state": "template",
              "primary_key": null,
              "properties":
               [
                {
                  "name": "default",
                  "value": true,
                  "form_options": {
                    "type": "hidden"
                  },
                  "title": "directory"
                },
                {
                  "name": "description",
                  "value": "default VPC security group",
                  "form_options": {
                    "type": "text"
                  },
                  "title": "Description"
                },
                 {
                  "name": "ip_permissions",
                  "value": [
                    {
                      "groups": [
                        {
                          "userId": "707082674943",
                          "groupId": "sg-31f91a56"
                        }
                      ],
                      "ipRanges": [],
                      "ipProtocol": "-1"
                    }
                  ],
                  "form_options": {
                    "type": "array"
                  },
                  "title": "Ip Permissions"
                },
                {
                  "name": "ip_permissions_egress",
                  "value": [
                    {
                      "groups": [],
                      "ipRanges": [
                        {
                          "cidrIp": "0.0.0.0/0"
                        }
                      ],
                      "ipProtocol": "-1"
                    }
                  ],
                  "form_options": {
                    "type": "array"
                  },
                  "title": "Ip Permissions Egress"
                },
                {
                  "name": "group_id",
                  "value": "sg-31f91a56",
                  "form_options": {
                    "type": "hidden"
                  },
                  "title": "Group Id"
                },
                {
                  "name": "uniq_provider_id",
                  "form_options": {
                    "type": "hidden"
                  },
                  "title": "Uniq Provider Id"
                }
              ],      
              "provides": [
                {
                  "name": "security_group",
                  "type": "Protocols::SecurityGroup",
                  "properties": [
                    {
                      "name": "port",
                      "form_options": {
                        "type": "integer"
                      },
                      "title": "Port"
                    }
                  ]
                }
              ],
              "service_type": "securitygroup",
              "type": "Services::Network::SecurityGroup::AWS",
              "vpc_id": "b4d269a4-49c5-42bf-b7c1-2818118fe6a7"
            },
             "tags": {
              "Name": "default"
            },
           "properties": [
              {
                "name": "default",
                "value": true,
                "form_options": {
                  "type": "hidden"
                },
                "title": "directory"
              },
              {
                "name": "description",
                "value": "default VPC security group",
                "form_options": {
                  "type": "text"
                },
                "title": "Description"
              }
            ],
            "interfaces": [
              {
                "id": "a6a6d2e4-077c-4ad0-aa54-9af0785c941e",
                "name": "default",
                "interface_type": "Protocols::SecurityGroup",
                "depends": false,
                "connections": []
              },
              {
                "id": "8adc2be2-ff89-453e-bb32-4ba9c37d5e95",
                "interface_type": "Protocols::Vpc",
                "depends": true,
                "connections": [
                  {
                    "id": "7fc6cc01-2374-47fc-b442-829241fe772f",
                    "interface_id": "8adc2be2-ff89-453e-bb32-4ba9c37d5e95",
                    "remote_interface_id": "30f34d56-7767-4f6e-b3d9-e138fc5d21e5",
                    "internal": false
                  }
                ]
              }
            ],
            "provides": [
              {
                "name": "security_group",
                "type": "Protocols::SecurityGroup",
                "properties": [
                  {
                    "name": "port",
                    "form_options": {
                      "type": "integer"
                    },
                    "title": "Port"
                  }
                ]
              }
            ],
            "depends": []
          },
          {
            "id": "8231c37b-8946-412f-88f3-89a2abd05c5b",
            "name": "atit-VPC",
            "type": "Services::Vpc",
            "additional_properties": {
              "parent_id": null,
              "depends": [],
              "draggable": true,
              "drawable": true,
              "edge": false,
              "generic_type": "Services::Vpc",
              "id": "8231c37b-8946-412f-88f3-89a2abd05c5b",
              "interfaces": [
                {
                  "id": "c6cb2561-6369-4bc4-98f4-4850ae2063a1",
                  "service_id": "8231c37b-8946-412f-88f3-89a2abd05c5b",
                  "name": "atit-VPC",
                  "interface_type": "Protocols::Vpc",
                  "created_at": "2016-11-14T07:38:52.036Z",
                  "updated_at": "2016-11-14T07:38:52.036Z",
                  "depends": false,
                  "connections": []
                }
              ],
              "name": "atit-VPC",
              "state": "running",
              "primary_key": "b4d269a4-49c5-42bf-b7c1-2818118fe6a7",
              "properties": [
                {
                  "name": "cidr_block",
                  "value": "10.0.0.0/16",
                  "form_options": {
                    "type": "text",
                    "readonly": "readonly"
                  },
                  "title": "CIDR Block"
                },
                {
                  "name": "vpc_id",
                  "value": "vpc-d10bdbb5",
                  "form_options": {
                    "type": "text",
                    "readonly": "readonly"
                  },
                  "title": "VPC ID"
                },
                {
                  "name": "internet_attached",
                  "value": true,
                  "form_options": {
                    "type": "hidden"
                  },
                  "title": "Internet Attached"
                },
                {
                  "name": "internet_gateway_id",
                  "value": "igw-3826655d",
                  "form_options": {
                    "type": "hidden"
                  },
                  "title": "Internet Gateway Id"
                },
                {
                  "name": "enable_dns_resolution",
                  "form_options": {
                    "type": "hidden"
                  },
                  "title": "Enable Dns Resolution"
                },
                {
                  "name": "enable_dns_hostnames",
                  "form_options": {
                    "type": "hidden"
                  },
                  "title": "Enable Dns Hostnames"
                }
              ],
              "provides": [
                {
                  "name": "Vpc",
                  "type": "Protocols::Vpc",
                  "properties": [
                    {
                      "name": "port",
                      "form_options": {
                        "type": "integer"
                      },
                      "title": "Port"
                    }
                  ]
                }
              ],
              "service_type": "vpc",
              "type": "Services::Vpc",
              "vpc_id": "b4d269a4-49c5-42bf-b7c1-2818118fe6a7"
            },
            "tags": {
              "Name": "atit-VPC"
            },
            "properties": [
              {
                "name": "cidr_block",
                "value": "10.0.0.0/16",
                "form_options": {
                  "type": "text",
                  "readonly": "readonly"
                },
                "title": "CIDR Block"
              },
              {
                "name": "vpc_id",
                "value": "vpc-d10bdbb5",
                "form_options": {
                  "type": "text",
                  "readonly": "readonly"
                },
                "title": "VPC ID"
              },
              {
                "name": "internet_attached",
                "value": true,
                "form_options": {
                  "type": "hidden"
                },
                "title": "Internet Attached"
              },
              {
                "name": "internet_gateway_id",
                "value": "igw-3826655d",
                "form_options": {
                  "type": "hidden"
                },
                "title": "Internet Gateway Id"
              },
              {
                "name": "enable_dns_resolution",
                "form_options": {
                  "type": "hidden"
                },
                "title": "Enable Dns Resolution"
              },
              {
                "name": "enable_dns_hostnames",
                "form_options": {
                  "type": "hidden"
                },
                "title": "Enable Dns Hostnames"
              }
            ],
            "interfaces": [
              {
                "id": "c6cb2561-6369-4bc4-98f4-4850ae2063a1",
                "name": "atit-VPC",
                "interface_type": "Protocols::Vpc",
                "depends": false,
                "connections": []
              }
            ],
            "provides": [
              {
                "name": "Vpc",
                "type": "Protocols::Vpc",
                "properties": [
                  {
                    "name": "port",
                    "form_options": {
                      "type": "integer"
                    },
                    "title": "Port"
                  }
                ]
              }
            ],
            "depends": []
          }, 
          {
            "id": "9a8376d8-7a46-4dbf-ad6c-87d0f011831f",
            "name": "default",
            "type": "Services::Network::SecurityGroup::AWS",
            "geometry": {
              "x": "80",
              "y": "20",
              "width": "0",
              "height": "0"
            },
            "additional_properties": {
              "parent_id": "4547810a-61f8-4b13-b185-434ea14fcc55",
              "depends": [],
              "draggable": false,
              "drawable": false,
              "edge": false,
              "generic_type": "Services::Network::SecurityGroup",
              "id": "9a8376d8-7a46-4dbf-ad6c-87d0f011831f",
              "interfaces": [
                {
                  "id": "260c033b-cff3-41af-8665-fcf5bfe54c8b",
                  "service_id": "9a8376d8-7a46-4dbf-ad6c-87d0f011831f",
                  "name": "default",
                  "interface_type": "Protocols::SecurityGroup",
                  "created_at": "2016-11-10T10:55:07.845Z",
                  "updated_at": "2016-11-10T10:55:07.849Z",
                  "depends": false,
                  "connections": []
                },
                {
                  "id": "f22bf8f2-22cd-47f0-98ab-e452a88277d1",
                  "service_id": "9a8376d8-7a46-4dbf-ad6c-87d0f011831f",
                  "name": "atit-VPC",
                  "interface_type": "Protocols::Vpc",
                  "created_at": "2016-11-10T10:55:07.853Z",
                  "updated_at": "2016-11-10T10:55:07.856Z",
                  "depends": true,
                  "connections": [
                    {
                      "id": "e4668e07-5a3e-4fa2-a0d3-e0fc927be9a5",
                      "interface_id": "f22bf8f2-22cd-47f0-98ab-e452a88277d1",
                      "remote_interface_id": "800d6d99-e26b-4ef0-816a-81a1eea52d83",
                      "created_at": "2016-11-10T10:55:08.051Z",
                      "updated_at": "2016-11-10T10:55:08.053Z",
                      "internal": false
                    }
                  ]
                }
              ],
              "name": "default",
              "state": "template",
              "primary_key": null,
              "properties": [
                {
                  "name": "default",
                  "value": true,
                  "form_options": {
                    "type": "hidden"
                  },
                  "title": "directory"
                },
                {
                  "name": "description",
                  "value": "default VPC security group",
                  "form_options": {
                    "type": "text"
                  },
                  "title": "Description"
                },
                {
                  "name": "ip_permissions",
                  "value": [
                    {
                      "groups": [
                        {
                          "userId": "707082674943",
                          "groupId": "sg-31f91a56"
                        }
                      ],
                      "ipRanges": [],
                      "ipProtocol": "-1"
                    }
                  ],
                  "form_options": {
                    "type": "array"
                  },
                  "title": "Ip Permissions"
                },
                {
                  "name": "ip_permissions_egress",
                  "value": [
                    {
                      "groups": [],
                      "ipRanges": [
                        {
                          "cidrIp": "0.0.0.0/0"
                        }
                      ],
                      "ipProtocol": "-1"
                    }
                  ],
                  "form_options": {
                    "type": "array"
                  },
                  "title": "Ip Permissions Egress"
                },
                {
                  "name": "group_id",
                  "value": "sg-31f91a56",
                  "form_options": {
                    "type": "hidden"
                  },
                  "title": "Group Id"
                },
                {
                  "name": "uniq_provider_id",
                  "form_options": {
                    "type": "hidden"
                  },
                  "title": "Uniq Provider Id"
                }
              ],
              "provides": [
                {
                  "name": "security_group",
                  "type": "Protocols::SecurityGroup",
                  "properties": [
                    {
                      "name": "port",
                      "form_options": {
                        "type": "integer"
                      },
                      "title": "Port"
                    }
                  ]
                }
              ],
              "service_type": "securitygroup",
              "type": "Services::Network::SecurityGroup::AWS",
              "vpc_id": "b4d269a4-49c5-42bf-b7c1-2818118fe6a7"
            },
            "tags": {
              "Name": "default"
            },
            "properties": [
              {
                "name": "default",
                "value": true,
                "form_options": {
                  "type": "hidden"
                },
                "title": "directory"
              },
              {
                "name": "description",
                "value": "default VPC security group",
                "form_options": {
                  "type": "text"
                },
                "title": "Description"
              },
              {
                "name": "ip_permissions",
                "value": [
                  {
                    "groups": [
                      {
                        "userId": "707082674943",
                        "groupId": "sg-31f91a56"
                      }
                    ],
                    "ipRanges": [],
                    "ipProtocol": "-1"
                  }
                ],
                "form_options": {
                  "type": "array"
                },
                "title": "Ip Permissions"
              },
              {
                "name": "ip_permissions_egress",
                "value": [
                  {
                    "groups": [],
                    "ipRanges": [
                      {
                        "cidrIp": "0.0.0.0/0"
                      }
                    ],
                    "ipProtocol": "-1"
                  }
                ],
                "form_options": {
                  "type": "array"
                },
                "title": "Ip Permissions Egress"
              },
              {
                "name": "group_id",
                "value": "sg-31f91a56",
                "form_options": {
                  "type": "hidden"
                },
                "title": "Group Id"
              },
              {
                "name": "uniq_provider_id",
                "form_options": {
                  "type": "hidden"
                },
                "title": "Uniq Provider Id"
              }
            ],
            "interfaces": [
              {
                "id": "260c033b-cff3-41af-8665-fcf5bfe54c8b",
                "name": "default",
                "interface_type": "Protocols::SecurityGroup",
                "depends": false,
                "connections": []
              },
              {
                "id": "f22bf8f2-22cd-47f0-98ab-e452a88277d1",
                "name": "atit-VPC",
                "interface_type": "Protocols::Vpc",
                "depends": true,
                "connections": [
                  {
                    "id": "e4668e07-5a3e-4fa2-a0d3-e0fc927be9a5",
                    "interface_id": "f22bf8f2-22cd-47f0-98ab-e452a88277d1",
                    "remote_interface_id": "800d6d99-e26b-4ef0-816a-81a1eea52d83",
                    "internal": false
                  }
                ]
              }
            ],
            "provides": [
              {
                "name": "security_group",
                "type": "Protocols::SecurityGroup",
                "properties": [
                  {
                    "name": "port",
                    "form_options": {
                      "type": "integer"
                    },
                    "title": "Port"
                  }
                ]
              }
            ],
            "depends": []
          },
          {
            "id": "84652162-60b0-4aef-bf70-0b0bf5a8881f",
            "name": "atit-private-sec-grp",
            "type": "Services::Network::SecurityGroup::AWS",
            "additional_properties": {
              "parent_id": "8231c37b-8946-412f-88f3-89a2abd05c5b",
              "depends": [],
              "draggable": false,
              "drawable": false,
              "edge": false,
              "generic_type": "Services::Network::SecurityGroup",
              "id": "84652162-60b0-4aef-bf70-0b0bf5a8881f",
              "interfaces": [
                {
                  "id": "a017f658-0f50-4d44-a7eb-b6b581d24d64",
                  "service_id": "84652162-60b0-4aef-bf70-0b0bf5a8881f",
                  "name": "atit-private-sec-grp",
                  "interface_type": "Protocols::SecurityGroup",
                  "created_at": "2016-11-14T07:38:52.062Z",
                  "updated_at": "2016-11-14T07:38:52.062Z",
                  "depends": false,
                  "connections": []
                },
                {
                  "id": "27daa919-2134-421c-9589-7b06b30937cd",
                  "service_id": "84652162-60b0-4aef-bf70-0b0bf5a8881f",
                  "name": null,
                  "interface_type": "Protocols::Vpc",
                  "created_at": "2016-11-14T07:38:52.685Z",
                  "updated_at": "2016-11-14T07:38:52.685Z",
                  "depends": true,
                  "connections": [
                    {
                      "id": "3abd33c8-5598-4003-9139-604c6b9d8487",
                      "interface_id": "27daa919-2134-421c-9589-7b06b30937cd",
                      "remote_interface_id": "c6cb2561-6369-4bc4-98f4-4850ae2063a1",
                      "created_at": "2016-11-14T07:38:52.691Z",
                      "updated_at": "2016-11-14T07:38:52.691Z",
                      "internal": false
                    }
                  ]
                }
              ],
              "name": "atit-private-sec-grp",
              "state": "running",
              "primary_key": null,
              "properties": [
                {
                  "name": "default",
                  "value": false,
                  "form_options": {
                    "type": "hidden"
                  },
                  "title": "directory"
                },
                {
                  "name": "description",
                  "value": "atit-private-sec-grp",
                  "form_options": {
                    "type": "text"
                  },
                  "title": "Description"
                },
                {
                  "name": "ip_permissions",
                  "value": [
                    {
                      "groups": [],
                      "ipRanges": [
                        {
                          "cidrIp": "10.0.1.0/24"
                        }
                      ],
                      "ipProtocol": "tcp",
                      "fromPort": "5432",
                      "toPort": "5432"
                    },
                    {
                      "groups": [],
                      "ipRanges": [
                        {
                          "cidrIp": "10.0.1.0/24"
                        },
                        {
                          "cidrIp": "0.0.0.0/0"
                        }
                      ],
                      "ipProtocol": "tcp",
                      "fromPort": "22",
                      "toPort": "22"
                    },
                    {
                      "groups": [],
                      "ipRanges": [
                        {
                          "cidrIp": "10.0.1.0/24"
                        }
                      ],
                      "ipProtocol": "icmp",
                      "fromPort": "-1",
                      "toPort": "-1"
                    }
                  ],
                  "form_options": {
                    "type": "array"
                  },
                  "title": "Ip Permissions"
                },
                {
                  "name": "ip_permissions_egress",
                  "value": [
                    {
                      "groups": [],
                      "ipRanges": [
                        {
                          "cidrIp": "0.0.0.0/0"
                        }
                      ],
                      "ipProtocol": "-1"
                    }
                  ],
                  "form_options": {
                    "type": "array"
                  },
                  "title": "Ip Permissions Egress"
                },
                {
                  "name": "group_id",
                  "value": "sg-c9c221ae",
                  "form_options": {
                    "type": "hidden"
                  },
                  "title": "Group Id"
                },
                {
                  "name": "uniq_provider_id",
                  "form_options": {
                    "type": "hidden"
                  },
                  "title": "Uniq Provider Id"
                }
              ],
              "provides": [
                {
                  "name": "security_group",
                  "type": "Protocols::SecurityGroup",
                  "properties": [
                    {
                      "name": "port",
                      "form_options": {
                        "type": "integer"
                      },
                      "title": "Port"
                    }
                  ]
                }
              ],
              "service_type": "securitygroup",
              "type": "Services::Network::SecurityGroup::AWS",
              "vpc_id": "b4d269a4-49c5-42bf-b7c1-2818118fe6a7"
            },
            "tags": {
              "Name": "atit-private-sec-group"
            },
            "properties": [
              {
                "name": "default",
                "value": false,
                "form_options": {
                  "type": "hidden"
                },
                "title": "directory"
              },
              {
                "name": "description",
                "value": "atit-private-sec-grp",
                "form_options": {
                  "type": "text"
                },
                "title": "Description"
              },
              {
                "name": "ip_permissions",
                "value": [
                  {
                    "groups": [],
                    "ipRanges": [
                      {
                        "cidrIp": "10.0.1.0/24"
                      }
                    ],
                    "ipProtocol": "tcp",
                    "fromPort": "5432",
                    "toPort": "5432"
                  },
                  {
                    "groups": [],
                    "ipRanges": [
                      {
                        "cidrIp": "10.0.1.0/24"
                      },
                      {
                        "cidrIp": "0.0.0.0/0"
                      }
                    ],
                    "ipProtocol": "tcp",
                    "fromPort": "22",
                    "toPort": "22"
                  },
                  {
                    "groups": [],
                    "ipRanges": [
                      {
                        "cidrIp": "10.0.1.0/24"
                      }
                    ],
                    "ipProtocol": "icmp",
                    "fromPort": "-1",
                    "toPort": "-1"
                  }
                ],
                "form_options": {
                  "type": "array"
                },
                "title": "Ip Permissions"
              },
              {
                "name": "ip_permissions_egress",
                "value": [
                  {
                    "groups": [],
                    "ipRanges": [
                      {
                        "cidrIp": "0.0.0.0/0"
                      }
                    ],
                    "ipProtocol": "-1"
                  }
                ],
                "form_options": {
                  "type": "array"
                },
                "title": "Ip Permissions Egress"
              },
              {
                "name": "group_id",
                "value": "sg-c9c221ae",
                "form_options": {
                  "type": "hidden"
                },
                "title": "Group Id"
              },
              {
                "name": "uniq_provider_id",
                "form_options": {
                  "type": "hidden"
                },
                "title": "Uniq Provider Id"
              }
            ],
            "interfaces": [
              {
                "id": "a017f658-0f50-4d44-a7eb-b6b581d24d64",
                "name": "atit-private-sec-grp",
                "interface_type": "Protocols::SecurityGroup",
                "depends": false,
                "connections": []
              },
              {
                "id": "27daa919-2134-421c-9589-7b06b30937cd",
                "interface_type": "Protocols::Vpc",
                "depends": true,
                "connections": [
                  {
                    "id": "3abd33c8-5598-4003-9139-604c6b9d8487",
                    "interface_id": "27daa919-2134-421c-9589-7b06b30937cd",
                    "remote_interface_id": "c6cb2561-6369-4bc4-98f4-4850ae2063a1",
                    "internal": false
                  }
                ]
              }
            ],
            "provides": [
              {
                "name": "security_group",
                "type": "Protocols::SecurityGroup",
                "properties": [
                  {
                    "name": "port",
                    "form_options": {
                      "type": "integer"
                    },
                    "title": "Port"
                  }
                ]
              }
            ],
            "depends": []
          },  
          {
            "id": "7659cd27-9e17-42a0-8185-f359603796eb",
            "name": "default",
            "type": "Services::Network::SecurityGroup::AWS",
            "geometry": {
              "x": "60",
              "y": "18",
              "width": "0",
              "height": "0"
            },
            "additional_properties": {
              "id": "7659cd27-9e17-42a0-8185-f359603796eb",
              "edge": false,
              "service_type": "securitygroup",
              "generic_type": "Services::Network::SecurityGroup",
              "type": "Services::Network::SecurityGroup::AWS",
              "name": "default",
              "drawable": false,
              "draggable": false,
              "interfaces": [
                {
                  "id": "679897ae-e191-4ac8-986a-cfd0d8bfda96",
                  "name": "default",
                  "depends": false,
                  "connections": null,
                  "interface_type": "Protocols::SecurityGroup"
                },
                {
                  "id": "9ebb19e9-791c-49a2-82e5-538ae5a4fb0a",
                  "name": "atit-VPC",
                  "depends": true,
                  "connections": [
                    {
                      "id": "190e38f5-52f0-48d8-8bd1-b5972c72f272",
                      "interface_id": "9ebb19e9-791c-49a2-82e5-538ae5a4fb0a",
                      "remote_interface_id": "28eb4c3c-5953-46a8-ad2b-8040f749e9a7",
                      "internal": false,
                      "type": "implicit"
                    }
                  ],
                  "interface_type": "Protocols::Vpc"
                }
              ],
              "vpc_id": "b4d269a4-49c5-42bf-b7c1-2818118fe6a7",
              "primary_key": "",
              "provides": [
                {
                  "name": "security_group",
                  "type": "Protocols::SecurityGroup",
                  "properties": [
                    {
                      "name": "port",
                      "form_options": {
                        "type": "integer"
                      },
                      "title": "Port"
                    }
                  ]
                }
              ],
              "depends": null,
              "parent_id": "7c6f634b-dbb6-4bcb-88c0-84d0c694d58d",
              "properties": [
                {
                  "name": "default",
                  "value": true,
                  "form_options": {
                    "type": "hidden"
                  },
                  "title": "directory"
                },
                {
                  "name": "description",
                  "value": "default VPC security group",
                  "form_options": {
                    "type": "text"
                  },
                  "title": "Description"
                },
                {
                  "name": "ip_permissions",
                  "value": [
                    {
                      "groups": [
                        {
                          "userId": "707082674943",
                          "groupId": "sg-31f91a56"
                        }
                      ],
                      "ipRanges": [],
                      "ipProtocol": "-1"
                    }
                  ],
                  "form_options": {
                    "type": "array"
                  },
                  "title": "Ip Permissions"
                },
                {
                  "name": "ip_permissions_egress",
                  "value": [
                    {
                      "groups": [],
                      "ipRanges": [
                        {
                          "cidrIp": "0.0.0.0/0"
                        }
                      ],
                      "ipProtocol": "-1"
                    }
                  ],
                  "form_options": {
                    "type": "array"
                  },
                  "title": "Ip Permissions Egress"
                },
                {
                  "name": "group_id",
                  "value": "sg-31f91a56",
                  "form_options": {
                    "type": "hidden"
                  },
                  "title": "Group Id"
                },
                {
                  "name": "uniq_provider_id",
                  "form_options": {
                    "type": "hidden"
                  },
                  "title": "Uniq Provider Id"
                }
              ]
            },
            "tags": {
              "Name": "default"
            },
            "properties": [
              {
                "name": "default",
                "value": true,
                "form_options": {
                  "type": "hidden"
                },
                "title": "directory"
              },
              {
                "name": "description",
                "value": "default VPC security group",
                "form_options": {
                  "type": "text"
                },
                "title": "Description"
              },
              {
                "name": "ip_permissions",
                "value": [
                  {
                    "groups": [
                      {
                        "userId": "707082674943",
                        "groupId": "sg-31f91a56"
                      }
                    ],
                    "ipRanges": [],
                    "ipProtocol": "-1"
                  }
                ],
                "form_options": {
                  "type": "array"
                },
                "title": "Ip Permissions"
              },
              {
                "name": "ip_permissions_egress",
                "value": [
                  {
                    "groups": [],
                    "ipRanges": [
                      {
                        "cidrIp": "0.0.0.0/0"
                      }
                    ],
                    "ipProtocol": "-1"
                  }
                ],
                "form_options": {
                  "type": "array"
                },
                "title": "Ip Permissions Egress"
              },
              {
                "name": "group_id",
                "value": "sg-31f91a56",
                "form_options": {
                  "type": "hidden"
                },
                "title": "Group Id"
              },
              {
                "name": "uniq_provider_id",
                "form_options": {
                  "type": "hidden"
                },
                "title": "Uniq Provider Id"
              }
            ],
            "interfaces": [
              {
                "id": "679897ae-e191-4ac8-986a-cfd0d8bfda96",
                "name": "default",
                "interface_type": "Protocols::SecurityGroup",
                "depends": false,
                "connections": []
              },
              {
                "id": "9ebb19e9-791c-49a2-82e5-538ae5a4fb0a",
                "name": "atit-VPC",
                "interface_type": "Protocols::Vpc",
                "depends": true,
                "connections": [
                  {
                    "id": "190e38f5-52f0-48d8-8bd1-b5972c72f272",
                    "interface_id": "9ebb19e9-791c-49a2-82e5-538ae5a4fb0a",
                    "remote_interface_id": "28eb4c3c-5953-46a8-ad2b-8040f749e9a7",
                    "internal": false
                  }
                ]
              }
            ],
            "provides": [
              {
                "name": "security_group",
                "type": "Protocols::SecurityGroup",
                "properties": [
                  {
                    "name": "port",
                    "form_options": {
                      "type": "integer"
                    },
                    "title": "Port"
                  }
                ]
              }
            ],
            "depends": []
          },
          {
            "id": "8fb65241-595f-4391-ad3f-e03de5630582",
            "name": " atit-sec-group-1",
            "type": "Services::Network::SecurityGroup::AWS",
            "additional_properties": {
              "parent_id": "8231c37b-8946-412f-88f3-89a2abd05c5b",
              "depends": [],
              "draggable": false,
              "drawable": false,
              "edge": false,
              "generic_type": "Services::Network::SecurityGroup",
              "id": "8fb65241-595f-4391-ad3f-e03de5630582",
              "interfaces": [
                {
                  "id": "5b972c87-9b50-437e-b83a-429edb2cb043",
                  "service_id": "8fb65241-595f-4391-ad3f-e03de5630582",
                  "name": " atit-sec-group-1",
                  "interface_type": "Protocols::SecurityGroup",
                  "created_at": "2016-11-14T07:38:52.085Z",
                  "updated_at": "2016-11-14T07:38:52.085Z",
                  "depends": false,
                  "connections": []
                },
                {
                  "id": "153c7342-a4e9-4f30-b077-94a340dd2f66",
                  "service_id": "8fb65241-595f-4391-ad3f-e03de5630582",
                  "name": null,
                  "interface_type": "Protocols::Vpc",
                  "created_at": "2016-11-14T07:38:52.831Z",
                  "updated_at": "2016-11-14T07:38:52.831Z",
                  "depends": true,
                  "connections": [
                    {
                      "id": "5fbddc2b-1650-4480-b5bd-341c5f6d0c13",
                      "interface_id": "153c7342-a4e9-4f30-b077-94a340dd2f66",
                      "remote_interface_id": "c6cb2561-6369-4bc4-98f4-4850ae2063a1",
                      "created_at": "2016-11-14T07:38:52.835Z",
                      "updated_at": "2016-11-14T07:38:52.835Z",
                      "internal": false
                    }
                  ]
                }
              ],
              "name": " atit-sec-group-1",
              "state": "running",
              "primary_key": null,
              "properties": [
                {
                  "name": "default",
                  "value": false,
                  "form_options": {
                    "type": "hidden"
                  },
                  "title": "directory"
                },
                {
                  "name": "description",
                  "value": " atit-sec-group-1",
                  "form_options": {
                    "type": "text"
                  },
                  "title": "Description"
                },
                {
                  "name": "ip_permissions",
                  "value": [
                    {
                      "groups": [],
                      "ipRanges": [
                        {
                          "cidrIp": "0.0.0.0/0"
                        }
                      ],
                      "ipProtocol": "tcp",
                      "fromPort": "80",
                      "toPort": "80"
                    },
                    {
                      "groups": [],
                      "ipRanges": [
                        {
                          "cidrIp": "0.0.0.0/0"
                        }
                      ],
                      "ipProtocol": "tcp",
                      "fromPort": "22",
                      "toPort": "22"
                    },
                    {
                      "groups": [],
                      "ipRanges": [
                        {
                          "cidrIp": "0.0.0.0/0"
                        }
                      ],
                      "ipProtocol": "tcp",
                      "fromPort": "443",
                      "toPort": "443"
                    }
                  ],
                  "form_options": {
                    "type": "array"
                  },
                  "title": "Ip Permissions"
                },
                {
                  "name": "ip_permissions_egress",
                  "value": [
                    {
                      "groups": [],
                      "ipRanges": [
                        {
                          "cidrIp": "0.0.0.0/0"
                        }
                      ],
                      "ipProtocol": "-1"
                    }
                  ],
                  "form_options": {
                    "type": "array"
                  },
                  "title": "Ip Permissions Egress"
                },
                {
                  "name": "group_id",
                  "value": "sg-98fd1eff",
                  "form_options": {
                    "type": "hidden"
                  },
                  "title": "Group Id"
                },
                {
                  "name": "uniq_provider_id",
                  "form_options": {
                    "type": "hidden"
                  },
                  "title": "Uniq Provider Id"
                }
              ],
              "provides": [
                {
                  "name": "security_group",
                  "type": "Protocols::SecurityGroup",
                  "properties": [
                    {
                      "name": "port",
                      "form_options": {
                        "type": "integer"
                      },
                      "title": "Port"
                    }
                  ]
                }
              ],
              "service_type": "securitygroup",
              "type": "Services::Network::SecurityGroup::AWS",
              "vpc_id": "b4d269a4-49c5-42bf-b7c1-2818118fe6a7"
            },
            "tags": {
              "Name": "atit-public-sec-gorup"
            },
            "properties": [
              {
                "name": "default",
                "value": false,
                "form_options": {
                  "type": "hidden"
                },
                "title": "directory"
              },
              {
                "name": "description",
                "value": " atit-sec-group-1",
                "form_options": {
                  "type": "text"
                },
                "title": "Description"
              },
              {
                "name": "ip_permissions",
                "value": [
                  {
                    "groups": [],
                    "ipRanges": [
                      {
                        "cidrIp": "0.0.0.0/0"
                      }
                    ],
                    "ipProtocol": "tcp",
                    "fromPort": "80",
                    "toPort": "80"
                  },
                  {
                    "groups": [],
                    "ipRanges": [
                      {
                        "cidrIp": "0.0.0.0/0"
                      }
                    ],
                    "ipProtocol": "tcp",
                    "fromPort": "22",
                    "toPort": "22"
                  },
                  {
                    "groups": [],
                    "ipRanges": [
                      {
                        "cidrIp": "0.0.0.0/0"
                      }
                    ],
                    "ipProtocol": "tcp",
                    "fromPort": "443",
                    "toPort": "443"
                  }
                ],
                "form_options": {
                  "type": "array"
                },
                "title": "Ip Permissions"
              },
              {
                "name": "ip_permissions_egress",
                "value": [
                  {
                    "groups": [],
                    "ipRanges": [
                      {
                        "cidrIp": "0.0.0.0/0"
                      }
                    ],
                    "ipProtocol": "-1"
                  }
                ],
                "form_options": {
                  "type": "array"
                },
                "title": "Ip Permissions Egress"
              },
              {
                "name": "group_id",
                "value": "sg-98fd1eff",
                "form_options": {
                  "type": "hidden"
                },
                "title": "Group Id"
              },
              {
                "name": "uniq_provider_id",
                "form_options": {
                  "type": "hidden"
                },
                "title": "Uniq Provider Id"
              }
            ],
            "interfaces": [
              {
                "id": "5b972c87-9b50-437e-b83a-429edb2cb043",
                "name": " atit-sec-group-1",
                "interface_type": "Protocols::SecurityGroup",
                "depends": false,
                "connections": []
              },
              {
                "id": "153c7342-a4e9-4f30-b077-94a340dd2f66",
                "interface_type": "Protocols::Vpc",
                "depends": true,
                "connections": [
                  {
                    "id": "5fbddc2b-1650-4480-b5bd-341c5f6d0c13",
                    "interface_id": "153c7342-a4e9-4f30-b077-94a340dd2f66",
                    "remote_interface_id": "c6cb2561-6369-4bc4-98f4-4850ae2063a1",
                    "internal": false
                  }
                ]
              }
            ],
            "provides": [
              {
                "name": "security_group",
                "type": "Protocols::SecurityGroup",
                "properties": [
                  {
                    "name": "port",
                    "form_options": {
                      "type": "integer"
                    },
                    "title": "Port"
                  }
                ]
              }
            ],
            "depends": []
          },
          {
            "id": "3c200e7a-a023-471f-a07b-42adcfa8d25a",
            "name": "default",
            "type": "Services::Network::SecurityGroup::AWS",
            "additional_properties": {
              "parent_id": "8231c37b-8946-412f-88f3-89a2abd05c5b",
              "depends": [],
              "draggable": false,
              "drawable": false,
              "edge": false,
              "generic_type": "Services::Network::SecurityGroup",
              "id": "3c200e7a-a023-471f-a07b-42adcfa8d25a",
              "interfaces": [
                {
                  "id": "c344d6ed-2eaa-424e-8eb6-0ec4a46850a8",
                  "service_id": "3c200e7a-a023-471f-a07b-42adcfa8d25a",
                  "name": "default",
                  "interface_type": "Protocols::SecurityGroup",
                  "created_at": "2016-11-14T07:38:52.108Z",
                  "updated_at": "2016-11-14T07:38:52.108Z",
                  "depends": false,
                  "connections": []
                },
                {
                  "id": "901829e1-f6f9-4156-9e1c-495bf0b63d45",
                  "service_id": "3c200e7a-a023-471f-a07b-42adcfa8d25a",
                  "name": null,
                  "interface_type": "Protocols::Vpc",
                  "created_at": "2016-11-14T07:38:52.965Z",
                  "updated_at": "2016-11-14T07:38:52.965Z",
                  "depends": true,
                  "connections": [
                    {
                      "id": "2009d902-9dfa-4593-bb09-4efdf1c33419",
                      "interface_id": "901829e1-f6f9-4156-9e1c-495bf0b63d45",
                      "remote_interface_id": "c6cb2561-6369-4bc4-98f4-4850ae2063a1",
                      "created_at": "2016-11-14T07:38:52.968Z",
                      "updated_at": "2016-11-14T07:38:52.968Z",
                      "internal": false
                    }
                  ]
                }
              ],
              "name": "default",
              "state": "running",
              "primary_key": null,
              "properties": [
                {
                  "name": "default",
                  "value": true,
                  "form_options": {
                    "type": "hidden"
                  },
                  "title": "directory"
                },
                {
                  "name": "description",
                  "value": "default VPC security group",
                  "form_options": {
                    "type": "text"
                  },
                  "title": "Description"
                },
                {
                  "name": "ip_permissions",
                  "value": [
                    {
                      "groups": [
                        {
                          "userId": "707082674943",
                          "groupId": "sg-31f91a56"
                        }
                      ],
                      "ipRanges": [],
                      "ipProtocol": "-1"
                    }
                  ],
                  "form_options": {
                    "type": "array"
                  },
                  "title": "Ip Permissions"
                },
                {
                  "name": "ip_permissions_egress",
                  "value": [
                    {
                      "groups": [],
                      "ipRanges": [
                        {
                          "cidrIp": "0.0.0.0/0"
                        }
                      ],
                      "ipProtocol": "-1"
                    }
                  ],
                  "form_options": {
                    "type": "array"
                  },
                  "title": "Ip Permissions Egress"
                },
                {
                  "name": "group_id",
                  "value": "sg-31f91a56",
                  "form_options": {
                    "type": "hidden"
                  },
                  "title": "Group Id"
                },
                {
                  "name": "uniq_provider_id",
                  "form_options": {
                    "type": "hidden"
                  },
                  "title": "Uniq Provider Id"
                }
              ],
              "provides": [
                {
                  "name": "security_group",
                  "type": "Protocols::SecurityGroup",
                  "properties": [
                    {
                      "name": "port",
                      "form_options": {
                        "type": "integer"
                      },
                      "title": "Port"
                    }
                  ]
                }
              ],
              "service_type": "securitygroup",
              "type": "Services::Network::SecurityGroup::AWS",
              "vpc_id": "b4d269a4-49c5-42bf-b7c1-2818118fe6a7"
            },
            "tags": {
              "Name": "default"
            },
            "properties": [
              {
                "name": "default",
                "value": true,
                "form_options": {
                  "type": "hidden"
                },
                "title": "directory"
              },
              {
                "name": "description",
                "value": "default VPC security group",
                "form_options": {
                  "type": "text"
                },
                "title": "Description"
              },
              {
                "name": "ip_permissions",
                "value": [
                  {
                    "groups": [
                      {
                        "userId": "707082674943",
                        "groupId": "sg-31f91a56"
                      }
                    ],
                    "ipRanges": [],
                    "ipProtocol": "-1"
                  }
                ],
                "form_options": {
                  "type": "array"
                },
                "title": "Ip Permissions"
              },
              {
                "name": "ip_permissions_egress",
                "value": [
                  {
                    "groups": [],
                    "ipRanges": [
                      {
                        "cidrIp": "0.0.0.0/0"
                      }
                    ],
                    "ipProtocol": "-1"
                  }
                ],
                "form_options": {
                  "type": "array"
                },
                "title": "Ip Permissions Egress"
              },
              {
                "name": "group_id",
                "value": "sg-31f91a56",
                "form_options": {
                  "type": "hidden"
                },
                "title": "Group Id"
              },
              {
                "name": "uniq_provider_id",
                "form_options": {
                  "type": "hidden"
                },
                "title": "Uniq Provider Id"
              }
            ],
            "interfaces": [
              {
                "id": "c344d6ed-2eaa-424e-8eb6-0ec4a46850a8",
                "name": "default",
                "interface_type": "Protocols::SecurityGroup",
                "depends": false,
                "connections": []
              },
              {
                "id": "901829e1-f6f9-4156-9e1c-495bf0b63d45",
                "interface_type": "Protocols::Vpc",
                "depends": true,
                "connections": [
                  {
                    "id": "2009d902-9dfa-4593-bb09-4efdf1c33419",
                    "interface_id": "901829e1-f6f9-4156-9e1c-495bf0b63d45",
                    "remote_interface_id": "c6cb2561-6369-4bc4-98f4-4850ae2063a1",
                    "internal": false
                  }
                ]
              }
            ],
            "provides": [
              {
                "name": "security_group",
                "type": "Protocols::SecurityGroup",
                "properties": [
                  {
                    "name": "port",
                    "form_options": {
                      "type": "integer"
                    },
                    "title": "Port"
                  }
                ]
              }
            ],
            "depends": []
          },
          {
            "id": "8529938f-1425-48d6-bd99-5d7736f20607",
            "name": "ap-southeast-1a",
            "type": "Services::Network::AvailabilityZone",
            "additional_properties": {
              "parent_id": "8231c37b-8946-412f-88f3-89a2abd05c5b",
              "depends": [],
              "draggable": true,
              "drawable": true,
              "edge": false,
              "generic_type": "Services::Network::AvailabilityZone",
              "id": "8529938f-1425-48d6-bd99-5d7736f20607",
              "interfaces": [
                {
                  "id": "d7aabf56-bf06-4f95-8ee9-508311898e1e",
                  "service_id": "8529938f-1425-48d6-bd99-5d7736f20607",
                  "name": "ap-southeast-1a",
                  "interface_type": "Protocols::AvailabilityZone",
                  "created_at": "2016-11-14T07:38:52.153Z",
                  "updated_at": "2016-11-14T07:38:52.153Z",
                  "depends": false,
                  "connections": []
                },
                {
                  "id": "6dfd4857-34b4-44da-b241-bcf75eb535ad",
                  "service_id": "8529938f-1425-48d6-bd99-5d7736f20607",
                  "name": null,
                  "interface_type": "Protocols::Vpc",
                  "created_at": "2016-11-14T07:38:55.061Z",
                  "updated_at": "2016-11-14T07:38:55.061Z",
                  "depends": true,
                  "connections": [
                    {
                      "id": "3ae18a36-5d74-489e-a83c-b0343d51ca86",
                      "interface_id": "6dfd4857-34b4-44da-b241-bcf75eb535ad",
                      "remote_interface_id": "c6cb2561-6369-4bc4-98f4-4850ae2063a1",
                      "created_at": "2016-11-14T07:38:55.065Z",
                      "updated_at": "2016-11-14T07:38:55.065Z",
                      "internal": false
                    }
                  ]
                }
              ],
              "name": "ap-southeast-1a",
              "state": "running",
              "primary_key": null,
              "properties": [
                {
                  "name": "code",
                  "value": "ap-southeast-1a",
                  "form_options": {
                    "type": "select",
                    "options": [
                      "ap-southeast-1a"
                    ]
                  },
                  "title": "Availability Zone Name"
                }
              ],
              "provides": [
                {
                  "name": "AvailabilityZone",
                  "type": "Protocols::AvailabilityZone",
                  "properties": [
                    {
                      "name": "port",
                      "form_options": {
                        "type": "integer"
                      },
                      "title": "Port"
                    }
                  ]
                }
              ],
              "service_type": "availabilityzone",
              "type": "Services::Network::AvailabilityZone",
              "vpc_id": "b4d269a4-49c5-42bf-b7c1-2818118fe6a7"
            },
            "properties": [
              {
                "name": "code",
                "value": "ap-southeast-1a",
                "form_options": {
                  "type": "select",
                  "options": [
                    "ap-southeast-1a"
                  ]
                },
                "title": "Availability Zone Name"
              }
            ],
            "interfaces": [
              {
                "id": "d7aabf56-bf06-4f95-8ee9-508311898e1e",
                "name": "ap-southeast-1a",
                "interface_type": "Protocols::AvailabilityZone",
                "depends": false,
                "connections": []
              },
              {
                "id": "6dfd4857-34b4-44da-b241-bcf75eb535ad",
                "interface_type": "Protocols::Vpc",
                "depends": true,
                "connections": [
                  {
                    "id": "3ae18a36-5d74-489e-a83c-b0343d51ca86",
                    "interface_id": "6dfd4857-34b4-44da-b241-bcf75eb535ad",
                    "remote_interface_id": "c6cb2561-6369-4bc4-98f4-4850ae2063a1",
                    "internal": false
                  }
                ]
              }
            ],
            "provides": [
              {
                "name": "AvailabilityZone",
                "type": "Protocols::AvailabilityZone",
                "properties": [
                  {
                    "name": "port",
                    "form_options": {
                      "type": "integer"
                    },
                    "title": "Port"
                  }
                ]
              }
            ],
            "depends": []
          },
          {
            "id": "960e21af-a37d-4145-b46b-ef4fca9c5d32",
            "name": "ap-southeast-1b",
            "type": "Services::Network::AvailabilityZone",
            "additional_properties": {
              "parent_id": "8231c37b-8946-412f-88f3-89a2abd05c5b",
              "depends": [],
              "draggable": true,
              "drawable": true,
              "edge": false,
              "generic_type": "Services::Network::AvailabilityZone",
              "id": "960e21af-a37d-4145-b46b-ef4fca9c5d32",
              "interfaces": [
                {
                  "id": "65ff2a0f-0fd7-45a7-b34a-56dcfb1d7f67",
                  "service_id": "960e21af-a37d-4145-b46b-ef4fca9c5d32",
                  "name": "ap-southeast-1b",
                  "interface_type": "Protocols::AvailabilityZone",
                  "created_at": "2016-11-14T07:38:52.131Z",
                  "updated_at": "2016-11-14T07:38:52.131Z",
                  "depends": false,
                  "connections": []
                },
                {
                  "id": "f7e097d0-f6d3-4994-8c7c-69c79ddd3040",
                  "service_id": "960e21af-a37d-4145-b46b-ef4fca9c5d32",
                  "name": null,
                  "interface_type": "Protocols::Vpc",
                  "created_at": "2016-11-14T07:38:53.098Z",
                  "updated_at": "2016-11-14T07:38:53.098Z",
                  "depends": true,
                  "connections": [
                    {
                      "id": "cc38884d-412c-4dd3-968d-de60434e1780",
                      "interface_id": "f7e097d0-f6d3-4994-8c7c-69c79ddd3040",
                      "remote_interface_id": "c6cb2561-6369-4bc4-98f4-4850ae2063a1",
                      "created_at": "2016-11-14T07:38:53.102Z",
                      "updated_at": "2016-11-14T07:38:53.102Z",
                      "internal": false
                    }
                  ]
                }
              ],
              "name": "ap-southeast-1b",
              "state": "running",
              "primary_key": null,
              "properties": [
                {
                  "name": "code",
                  "value": "ap-southeast-1b",
                  "form_options": {
                    "type": "select",
                    "options": [
                      "ap-southeast-1b"
                    ]
                  },
                  "title": "Availability Zone Name"
                }
              ],
              "provides": [
                {
                  "name": "AvailabilityZone",
                  "type": "Protocols::AvailabilityZone",
                  "properties": [
                    {
                      "name": "port",
                      "form_options": {
                        "type": "integer"
                      },
                      "title": "Port"
                    }
                  ]
                }
              ],
              "service_type": "availabilityzone",
              "type": "Services::Network::AvailabilityZone",
              "vpc_id": "b4d269a4-49c5-42bf-b7c1-2818118fe6a7"
            },
            "properties": [
              {
                "name": "code",
                "value": "ap-southeast-1b",
                "form_options": {
                  "type": "select",
                  "options": [
                    "ap-southeast-1b"
                  ]
                },
                "title": "Availability Zone Name"
              }
            ],
            "interfaces": [
              {
                "id": "65ff2a0f-0fd7-45a7-b34a-56dcfb1d7f67",
                "name": "ap-southeast-1b",
                "interface_type": "Protocols::AvailabilityZone",
                "depends": false,
                "connections": []
              },
              {
                "id": "f7e097d0-f6d3-4994-8c7c-69c79ddd3040",
                "interface_type": "Protocols::Vpc",
                "depends": true,
                "connections": [
                  {
                    "id": "cc38884d-412c-4dd3-968d-de60434e1780",
                    "interface_id": "f7e097d0-f6d3-4994-8c7c-69c79ddd3040",
                    "remote_interface_id": "c6cb2561-6369-4bc4-98f4-4850ae2063a1",
                    "internal": false
                  }
                ]
              }
            ],
            "provides": [
              {
                "name": "AvailabilityZone",
                "type": "Protocols::AvailabilityZone",
                "properties": [
                  {
                    "name": "port",
                    "form_options": {
                      "type": "integer"
                    },
                    "title": "Port"
                  }
                ]
              }
            ],
            "depends": []
          },       
          {
            "id": "9d3fbe03-e0ce-47a8-be57-1e7de7cd5468",
            "name": "main",
            "type": "Services::Network::RouteTable::AWS",
            "additional_properties": {
              "parent_id": "8231c37b-8946-412f-88f3-89a2abd05c5b",
              "depends": [],
              "draggable": false,
              "drawable": true,
              "edge": false,
              "generic_type": "Services::Network::RouteTable",
              "id": "9d3fbe03-e0ce-47a8-be57-1e7de7cd5468",
              "interfaces": [
                {
                  "id": "52e3e522-4b69-461a-8626-bac2b7d8ec30",
                  "service_id": "9d3fbe03-e0ce-47a8-be57-1e7de7cd5468",
                  "name": "main",
                  "interface_type": "Protocols::RouteTable",
                  "created_at": "2016-11-14T07:38:52.176Z",
                  "updated_at": "2016-11-14T07:38:52.176Z",
                  "depends": false,
                  "connections": []
                },
                {
                  "id": "35bb19c6-f884-4d38-bf52-d096576f3aeb",
                  "service_id": "9d3fbe03-e0ce-47a8-be57-1e7de7cd5468",
                  "name": null,
                  "interface_type": "Protocols::Vpc",
                  "created_at": "2016-11-14T07:38:57.015Z",
                  "updated_at": "2016-11-14T07:38:57.015Z",
                  "depends": true,
                  "connections": [
                    {
                      "id": "0bdd85c8-3bf7-4e43-a75d-6fdfdc346a6f",
                      "interface_id": "35bb19c6-f884-4d38-bf52-d096576f3aeb",
                      "remote_interface_id": "c6cb2561-6369-4bc4-98f4-4850ae2063a1",
                      "created_at": "2016-11-14T07:38:57.018Z",
                      "updated_at": "2016-11-14T07:38:57.018Z",
                      "internal": false
                    }
                  ]
                }
              ],
              "name": "main",
              "state": "running",
              "primary_key": null,
              "properties": [
                {
                  "name": "main",
                  "value": true,
                  "form_options": {
                    "type": "hidden"
                  },
                  "title": "directory"
                },
                {
                  "name": "route_table_id",
                  "value": "rtb-b0e471d4",
                  "form_options": {
                    "type": "text",
                    "readonly": true
                  },
                  "title": "RouteTable Id"
                },
                {
                  "name": "routes",
                  "value": [
                    {
                      "destinationCidrBlock": "10.0.0.0/16",
                      "gatewayId": "local",
                      "instanceId": null,
                      "instanceOwnerId": null,
                      "networkInterfaceId": null,
                      "vpcPeeringConnectionId": null,
                      "state": "active",
                      "origin": "CreateRouteTable"
                    },
                    {
                      "destinationCidrBlock": "0.0.0.0/0",
                      "gatewayId": null,
                      "instanceId": null,
                      "instanceOwnerId": null,
                      "networkInterfaceId": null,
                      "vpcPeeringConnectionId": null,
                      "state": "active",
                      "origin": "CreateRoute"
                    }
                  ],
                  "form_options": {
                    "type": "text",
                    "readonly": true
                  },
                  "title": "Routes"
                }
              ],
              "provides": [
                {
                  "name": "route_table",
                  "type": "Protocols::RouteTable",
                  "properties": [
                    {
                      "name": "port",
                      "form_options": {
                        "type": "integer"
                      },
                      "title": "Port"
                    }
                  ]
                }
              ],
              "service_type": "routetable",
              "type": "Services::Network::RouteTable::AWS",
              "vpc_id": "b4d269a4-49c5-42bf-b7c1-2818118fe6a7"
            },
            "tags": {
              "Name": "main"
            },
            "properties": [
              {
                "name": "main",
                "value": true,
                "form_options": {
                  "type": "hidden"
                },
                "title": "directory"
              },
              {
                "name": "route_table_id",
                "value": "rtb-b0e471d4",
                "form_options": {
                  "type": "text",
                  "readonly": true
                },
                "title": "RouteTable Id"
              },
              {
                "name": "routes",
                "value": [
                  {
                    "destinationCidrBlock": "10.0.0.0/16",
                    "gatewayId": "local",
                    "instanceId": null,
                    "instanceOwnerId": null,
                    "networkInterfaceId": null,
                    "vpcPeeringConnectionId": null,
                    "state": "active",
                    "origin": "CreateRouteTable"
                  },
                  {
                    "destinationCidrBlock": "0.0.0.0/0",
                    "gatewayId": null,
                    "instanceId": null,
                    "instanceOwnerId": null,
                    "networkInterfaceId": null,
                    "vpcPeeringConnectionId": null,
                    "state": "active",
                    "origin": "CreateRoute"
                  }
                ],
                "form_options": {
                  "type": "text",
                  "readonly": true
                },
                "title": "Routes"
              }
            ],
            "interfaces": [
              {
                "id": "52e3e522-4b69-461a-8626-bac2b7d8ec30",
                "name": "main",
                "interface_type": "Protocols::RouteTable",
                "depends": false,
                "connections": []
              },
              {
                "id": "35bb19c6-f884-4d38-bf52-d096576f3aeb",
                "interface_type": "Protocols::Vpc",
                "depends": true,
                "connections": [
                  {
                    "id": "0bdd85c8-3bf7-4e43-a75d-6fdfdc346a6f",
                    "interface_id": "35bb19c6-f884-4d38-bf52-d096576f3aeb",
                    "remote_interface_id": "c6cb2561-6369-4bc4-98f4-4850ae2063a1",
                    "internal": false
                  }
                ]
              }
            ],
            "provides": [
              {
                "name": "route_table",
                "type": "Protocols::RouteTable",
                "properties": [
                  {
                    "name": "port",
                    "form_options": {
                      "type": "integer"
                    },
                    "title": "Port"
                  }
                ]
              }
            ],
            "depends": []
          },
          {
            "id": "004ac34a-df99-4802-83c5-5675c7601f28",
            "name": "atit-public-route",
            "type": "Services::Network::RouteTable::AWS",
            "additional_properties": {
              "parent_id": "8231c37b-8946-412f-88f3-89a2abd05c5b",
              "depends": [],
              "draggable": false,
              "drawable": true,
              "edge": false,
              "generic_type": "Services::Network::RouteTable",
              "id": "004ac34a-df99-4802-83c5-5675c7601f28",
              "interfaces": [
                {
                  "id": "cfef8b36-5e0c-44d2-bf53-929efc9f90e6",
                  "service_id": "004ac34a-df99-4802-83c5-5675c7601f28",
                  "name": "atit-public-route",
                  "interface_type": "Protocols::RouteTable",
                  "created_at": "2016-11-14T07:38:52.221Z",
                  "updated_at": "2016-11-14T07:38:52.221Z",
                  "depends": false,
                  "connections": []
                },
                {
                  "id": "30edb7a2-b6dc-40b3-9733-618c48403383",
                  "service_id": "004ac34a-df99-4802-83c5-5675c7601f28",
                  "name": null,
                  "interface_type": "Protocols::Vpc",
                  "created_at": "2016-11-14T07:38:57.407Z",
                  "updated_at": "2016-11-14T07:38:57.407Z",
                  "depends": true,
                  "connections": [
                    {
                      "id": "27fd0e33-c29e-496f-b7d1-3b0ee6517043",
                      "interface_id": "30edb7a2-b6dc-40b3-9733-618c48403383",
                      "remote_interface_id": "c6cb2561-6369-4bc4-98f4-4850ae2063a1",
                      "created_at": "2016-11-14T07:38:57.411Z",
                      "updated_at": "2016-11-14T07:38:57.411Z",
                      "internal": false
                    }
                  ]
                },
                {
                  "id": "83e5606c-3e20-46bd-9911-d86cb25da7bc",
                  "service_id": "004ac34a-df99-4802-83c5-5675c7601f28",
                  "name": null,
                  "interface_type": "Protocols::Subnet",
                  "created_at": "2016-11-14T07:38:57.515Z",
                  "updated_at": "2016-11-14T07:38:57.515Z",
                  "depends": true,
                  "connections": [
                    {
                      "id": "a571c784-a0ec-43d7-9c10-764b09965d2c",
                      "interface_id": "83e5606c-3e20-46bd-9911-d86cb25da7bc",
                      "remote_interface_id": "39f5d424-f32f-42df-bb5f-db8dfb5a55e0",
                      "created_at": "2016-11-14T07:38:57.518Z",
                      "updated_at": "2016-11-14T07:38:57.518Z",
                      "internal": false
                    }
                  ]
                }
              ],
              "name": "atit-public-route",
              "state": "running",
              "primary_key": null,
              "properties": [
                {
                  "name": "main",
                  "value": false,
                  "form_options": {
                    "type": "hidden"
                  },
                  "title": "directory"
                },
                {
                  "name": "route_table_id",
                  "value": "rtb-e2e67386",
                  "form_options": {
                    "type": "text",
                    "readonly": true
                  },
                  "title": "RouteTable Id"
                },
                {
                  "name": "routes",
                  "value": [
                    {
                      "destinationCidrBlock": "10.0.0.0/16",
                      "gatewayId": "local",
                      "instanceId": null,
                      "instanceOwnerId": null,
                      "networkInterfaceId": null,
                      "vpcPeeringConnectionId": null,
                      "state": "active",
                      "origin": "CreateRouteTable"
                    },
                    {
                      "destinationCidrBlock": "0.0.0.0/0",
                      "gatewayId": "igw-3826655d",
                      "instanceId": null,
                      "instanceOwnerId": null,
                      "networkInterfaceId": null,
                      "vpcPeeringConnectionId": null,
                      "state": "active",
                      "origin": "CreateRoute"
                    }
                  ],
                  "form_options": {
                    "type": "text",
                    "readonly": true
                  },
                  "title": "Routes"
                }
              ],
              "provides": [
                {
                  "name": "route_table",
                  "type": "Protocols::RouteTable",
                  "properties": [
                    {
                      "name": "port",
                      "form_options": {
                        "type": "integer"
                      },
                      "title": "Port"
                    }
                  ]
                }
              ],
              "service_type": "routetable",
              "type": "Services::Network::RouteTable::AWS",
              "vpc_id": "b4d269a4-49c5-42bf-b7c1-2818118fe6a7"
            },
            "tags": {
              "Name": "atit-public-route"
            },
            "properties": [
              {
                "name": "main",
                "value": false,
                "form_options": {
                  "type": "hidden"
                },
                "title": "directory"
              },
              {
                "name": "route_table_id",
                "value": "rtb-e2e67386",
                "form_options": {
                  "type": "text",
                  "readonly": true
                },
                "title": "RouteTable Id"
              },
              {
                "name": "routes",
                "value": [
                  {
                    "destinationCidrBlock": "10.0.0.0/16",
                    "gatewayId": "local",
                    "instanceId": null,
                    "instanceOwnerId": null,
                    "networkInterfaceId": null,
                    "vpcPeeringConnectionId": null,
                    "state": "active",
                    "origin": "CreateRouteTable"
                  },
                  {
                    "destinationCidrBlock": "0.0.0.0/0",
                    "gatewayId": "igw-3826655d",
                    "instanceId": null,
                    "instanceOwnerId": null,
                    "networkInterfaceId": null,
                    "vpcPeeringConnectionId": null,
                    "state": "active",
                    "origin": "CreateRoute"
                  }
                ],
                "form_options": {
                  "type": "text",
                  "readonly": true
                },
                "title": "Routes"
              }
            ],
            "interfaces": [
              {
                "id": "cfef8b36-5e0c-44d2-bf53-929efc9f90e6",
                "name": "atit-public-route",
                "interface_type": "Protocols::RouteTable",
                "depends": false,
                "connections": []
              },
              {
                "id": "30edb7a2-b6dc-40b3-9733-618c48403383",
                "interface_type": "Protocols::Vpc",
                "depends": true,
                "connections": [
                  {
                    "id": "27fd0e33-c29e-496f-b7d1-3b0ee6517043",
                    "interface_id": "30edb7a2-b6dc-40b3-9733-618c48403383",
                    "remote_interface_id": "c6cb2561-6369-4bc4-98f4-4850ae2063a1",
                    "internal": false
                  }
                ]
              },
              {
                "id": "83e5606c-3e20-46bd-9911-d86cb25da7bc",
                "interface_type": "Protocols::Subnet",
                "depends": true,
                "connections": [
                  {
                    "id": "a571c784-a0ec-43d7-9c10-764b09965d2c",
                    "interface_id": "83e5606c-3e20-46bd-9911-d86cb25da7bc",
                    "remote_interface_id": "39f5d424-f32f-42df-bb5f-db8dfb5a55e0",
                    "internal": false
                  }
                ]
              }
            ],
            "provides": [
              {
                "name": "route_table",
                "type": "Protocols::RouteTable",
                "properties": [
                  {
                    "name": "port",
                    "form_options": {
                      "type": "integer"
                    },
                    "title": "Port"
                  }
                ]
              }
            ],
            "depends": []
          },
          {
            "id": "2c289d63-be5d-42b9-affb-8c4a55e429fe",
            "name": "dasd",
            "type": "Services::Network::RouteTable::AWS",
            "additional_properties": {
              "parent_id": "8231c37b-8946-412f-88f3-89a2abd05c5b",
              "depends": [],
              "draggable": false,
              "drawable": true,
              "edge": false,
              "generic_type": "Services::Network::RouteTable",
              "id": "2c289d63-be5d-42b9-affb-8c4a55e429fe",
              "interfaces": [
                {
                  "id": "f76f0c9c-aba5-447b-b229-76b564774809",
                  "service_id": "2c289d63-be5d-42b9-affb-8c4a55e429fe",
                  "name": "dasd",
                  "interface_type": "Protocols::RouteTable",
                  "created_at": "2016-11-14T07:38:52.199Z",
                  "updated_at": "2016-11-14T07:38:52.199Z",
                  "depends": false,
                  "connections": []
                },
                {
                  "id": "e1356319-77a5-440b-a572-6fcb703883fb",
                  "service_id": "2c289d63-be5d-42b9-affb-8c4a55e429fe",
                  "name": null,
                  "interface_type": "Protocols::Vpc",
                  "created_at": "2016-11-14T07:38:57.208Z",
                  "updated_at": "2016-11-14T07:38:57.208Z",
                  "depends": true,
                  "connections": [
                    {
                      "id": "80f33fe5-2bc0-4261-90a4-397b8b6c906c",
                      "interface_id": "e1356319-77a5-440b-a572-6fcb703883fb",
                      "remote_interface_id": "c6cb2561-6369-4bc4-98f4-4850ae2063a1",
                      "created_at": "2016-11-14T07:38:57.211Z",
                      "updated_at": "2016-11-14T07:38:57.211Z",
                      "internal": false
                    }
                  ]
                }
              ],
              "name": "dasd",
              "state": "running",
              "primary_key": null,
              "properties": [
                {
                  "name": "main",
                  "value": false,
                  "form_options": {
                    "type": "hidden"
                  },
                  "title": "directory"
                },
                {
                  "name": "route_table_id",
                  "value": "rtb-bb1b8edf",
                  "form_options": {
                    "type": "text",
                    "readonly": true
                  },
                  "title": "RouteTable Id"
                },
                {
                  "name": "routes",
                  "value": [
                    {
                      "destinationCidrBlock": "10.0.0.0/16",
                      "gatewayId": "local",
                      "instanceId": null,
                      "instanceOwnerId": null,
                      "networkInterfaceId": null,
                      "vpcPeeringConnectionId": null,
                      "state": "active",
                      "origin": "CreateRouteTable"
                    }
                  ],
                  "form_options": {
                    "type": "text",
                    "readonly": true
                  },
                  "title": "Routes"
                }
              ],
              "provides": [
                {
                  "name": "route_table",
                  "type": "Protocols::RouteTable",
                  "properties": [
                    {
                      "name": "port",
                      "form_options": {
                        "type": "integer"
                      },
                      "title": "Port"
                    }
                  ]
                }
              ],
              "service_type": "routetable",
              "type": "Services::Network::RouteTable::AWS",
              "vpc_id": "b4d269a4-49c5-42bf-b7c1-2818118fe6a7"
            },
            "tags": {
              "Name": "dasd"
            },
            "properties": [
              {
                "name": "main",
                "value": false,
                "form_options": {
                  "type": "hidden"
                },
                "title": "directory"
              },
              {
                "name": "route_table_id",
                "value": "rtb-bb1b8edf",
                "form_options": {
                  "type": "text",
                  "readonly": true
                },
                "title": "RouteTable Id"
              },
              {
                "name": "routes",
                "value": [
                  {
                    "destinationCidrBlock": "10.0.0.0/16",
                    "gatewayId": "local",
                    "instanceId": null,
                    "instanceOwnerId": null,
                    "networkInterfaceId": null,
                    "vpcPeeringConnectionId": null,
                    "state": "active",
                    "origin": "CreateRouteTable"
                  }
                ],
                "form_options": {
                  "type": "text",
                  "readonly": true
                },
                "title": "Routes"
              }
            ],
            "interfaces": [
              {
                "id": "f76f0c9c-aba5-447b-b229-76b564774809",
                "name": "dasd",
                "interface_type": "Protocols::RouteTable",
                "depends": false,
                "connections": []
              },
              {
                "id": "e1356319-77a5-440b-a572-6fcb703883fb",
                "interface_type": "Protocols::Vpc",
                "depends": true,
                "connections": [
                  {
                    "id": "80f33fe5-2bc0-4261-90a4-397b8b6c906c",
                    "interface_id": "e1356319-77a5-440b-a572-6fcb703883fb",
                    "remote_interface_id": "c6cb2561-6369-4bc4-98f4-4850ae2063a1",
                    "internal": false
                  }
                ]
              }
            ],
            "provides": [
              {
                "name": "route_table",
                "type": "Protocols::RouteTable",
                "properties": [
                  {
                    "name": "port",
                    "form_options": {
                      "type": "integer"
                    },
                    "title": "Port"
                  }
                ]
              }
            ],
            "depends": []
          },
          {
            "id": "8c7ac095-ed1f-4e6c-8b7f-b896ef9e4681",
            "name": "atit-igw",
            "type": "Services::Network::InternetGateway::AWS",
            "additional_properties": {
              "parent_id": "8231c37b-8946-412f-88f3-89a2abd05c5b",
              "depends": [],
              "draggable": false,
              "drawable": true,
              "edge": false,
              "generic_type": "Services::Network::InternetGateway",
              "id": "8c7ac095-ed1f-4e6c-8b7f-b896ef9e4681",
              "interfaces": [
                {
                  "id": "2f0f2f01-d43d-4d30-8812-400ceaf8018a",
                  "service_id": "8c7ac095-ed1f-4e6c-8b7f-b896ef9e4681",
                  "name": "atit-igw",
                  "interface_type": "Protocols::InternetGateway",
                  "created_at": "2016-11-14T07:38:52.244Z",
                  "updated_at": "2016-11-14T07:38:52.244Z",
                  "depends": false,
                  "connections": []
                },
                {
                  "id": "3cae9cfc-a820-43d8-b04a-5b7af89025cd",
                  "service_id": "8c7ac095-ed1f-4e6c-8b7f-b896ef9e4681",
                  "name": null,
                  "interface_type": "Protocols::RouteTable",
                  "created_at": "2016-11-14T07:38:57.067Z",
                  "updated_at": "2016-11-14T07:38:57.067Z",
                  "depends": true,
                  "connections": [
                    {
                      "id": "0f647dc2-ced5-4b99-be95-79b383e7ddd7",
                      "interface_id": "3cae9cfc-a820-43d8-b04a-5b7af89025cd",
                      "remote_interface_id": "52e3e522-4b69-461a-8626-bac2b7d8ec30",
                      "created_at": "2016-11-14T07:38:57.070Z",
                      "updated_at": "2016-11-14T07:38:57.070Z",
                      "internal": false
                    },
                    {
                      "id": "e53690da-ee3e-4993-8bd1-b7d5404c296d",
                      "interface_id": "3cae9cfc-a820-43d8-b04a-5b7af89025cd",
                      "remote_interface_id": "f76f0c9c-aba5-447b-b229-76b564774809",
                      "created_at": "2016-11-14T07:38:57.270Z",
                      "updated_at": "2016-11-14T07:38:57.270Z",
                      "internal": false
                    },
                    {
                      "id": "b304f531-ba28-464c-ab4a-43be97e0ded6",
                      "interface_id": "3cae9cfc-a820-43d8-b04a-5b7af89025cd",
                      "remote_interface_id": "cfef8b36-5e0c-44d2-bf53-929efc9f90e6",
                      "created_at": "2016-11-14T07:38:57.468Z",
                      "updated_at": "2016-11-14T07:38:57.468Z",
                      "internal": false
                    }
                  ]
                },
                {
                  "id": "381559fb-ae77-4f14-87e1-9b3651582b5f",
                  "service_id": "8c7ac095-ed1f-4e6c-8b7f-b896ef9e4681",
                  "name": null,
                  "interface_type": "Protocols::Vpc",
                  "created_at": "2016-11-14T07:38:57.666Z",
                  "updated_at": "2016-11-14T07:38:57.666Z",
                  "depends": true,
                  "connections": [
                    {
                      "id": "0c650026-45d4-41a0-8f53-482e54dd67ec",
                      "interface_id": "381559fb-ae77-4f14-87e1-9b3651582b5f",
                      "remote_interface_id": "c6cb2561-6369-4bc4-98f4-4850ae2063a1",
                      "created_at": "2016-11-14T07:38:57.669Z",
                      "updated_at": "2016-11-14T07:38:57.669Z",
                      "internal": false
                    }
                  ]
                }
              ],
              "name": "atit-igw",
              "state": "running",
              "primary_key": null,
              "properties": [
                {
                  "name": "internet_gateway_id",
                  "value": "igw-3826655d",
                  "form_options": {
                    "type": "hidden"
                  },
                  "title": "Internet Gateway Id"
                }
              ],
              "provides": [
                {
                  "name": "internet_gateway",
                  "type": "Protocols::InternetGateway",
                  "properties": [
                    {
                      "name": "port",
                      "form_options": {
                        "type": "integer"
                      },
                      "title": "Port"
                    }
                  ]
                }
              ],
              "service_type": "internetgateway",
              "type": "Services::Network::InternetGateway::AWS",
              "vpc_id": "b4d269a4-49c5-42bf-b7c1-2818118fe6a7"
            },
            "tags": {
              "Name": "atit-igw"
            },
            "properties": [
              {
                "name": "internet_gateway_id",
                "value": "igw-3826655d",
                "form_options": {
                  "type": "hidden"
                },
                "title": "Internet Gateway Id"
              }
            ],
            "interfaces": [
              {
                "id": "2f0f2f01-d43d-4d30-8812-400ceaf8018a",
                "name": "atit-igw",
                "interface_type": "Protocols::InternetGateway",
                "depends": false,
                "connections": []
              },
              {
                "id": "3cae9cfc-a820-43d8-b04a-5b7af89025cd",
                "interface_type": "Protocols::RouteTable",
                "depends": true,
                "connections": [
                  {
                    "id": "0f647dc2-ced5-4b99-be95-79b383e7ddd7",
                    "interface_id": "3cae9cfc-a820-43d8-b04a-5b7af89025cd",
                    "remote_interface_id": "52e3e522-4b69-461a-8626-bac2b7d8ec30",
                    "internal": false
                  },
                  {
                    "id": "e53690da-ee3e-4993-8bd1-b7d5404c296d",
                    "interface_id": "3cae9cfc-a820-43d8-b04a-5b7af89025cd",
                    "remote_interface_id": "f76f0c9c-aba5-447b-b229-76b564774809",
                    "internal": false
                  },
                  {
                    "id": "b304f531-ba28-464c-ab4a-43be97e0ded6",
                    "interface_id": "3cae9cfc-a820-43d8-b04a-5b7af89025cd",
                    "remote_interface_id": "cfef8b36-5e0c-44d2-bf53-929efc9f90e6",
                    "internal": false
                  }
                ]
              },
              {
                "id": "381559fb-ae77-4f14-87e1-9b3651582b5f",
                "interface_type": "Protocols::Vpc",
                "depends": true,
                "connections": [
                  {
                    "id": "0c650026-45d4-41a0-8f53-482e54dd67ec",
                    "interface_id": "381559fb-ae77-4f14-87e1-9b3651582b5f",
                    "remote_interface_id": "c6cb2561-6369-4bc4-98f4-4850ae2063a1",
                    "internal": false
                  }
                ]
              }
            ],
            "provides": [
              {
                "name": "internet_gateway",
                "type": "Protocols::InternetGateway",
                "properties": [
                  {
                    "name": "port",
                    "form_options": {
                      "type": "integer"
                    },
                    "title": "Port"
                  }
                ]
              }
            ],
            "depends": []
          },
          {
            "id": "48d93535-3850-49b0-a81f-a1181c09d406",
            "name": "atit-subnet2",
            "type": "Services::Network::Subnet::AWS",
            "container": true,
            "additional_properties": {
              "parent_id": "960e21af-a37d-4145-b46b-ef4fca9c5d32",
              "depends": [
                {
                  "name": "gateway",
                  "protocol": {
                    "generated_attribute_methods": {
                      "_mutex": {}
                    },
                    "attribute_methods_generated": false,
                    "relation_delegate_cache": {
                      "ActiveRecord::Relation": {
                        "delegation_mutex": {}
                      },
                      "ActiveRecord::Associations::CollectionProxy": {
                        "delegation_mutex": {}
                      },
                      "ActiveRecord::AssociationRelation": {
                        "delegation_mutex": {}
                      }
                    }
                  }
                }
              ],
              "draggable": true,
              "drawable": true,
              "edge": false,
              "generic_type": "Services::Network::Subnet",
              "id": "48d93535-3850-49b0-a81f-a1181c09d406",
              "interfaces": [
                {
                  "id": "31fe310c-eda2-4842-8c6d-f53cacf7257b",
                  "service_id": "48d93535-3850-49b0-a81f-a1181c09d406",
                  "name": "atit-subnet2",
                  "interface_type": "Protocols::Subnet",
                  "created_at": "2016-11-14T07:38:52.289Z",
                  "updated_at": "2016-11-14T07:38:52.289Z",
                  "depends": false,
                  "connections": []
                },
                {
                  "id": "8b6f05d1-6d30-4ecd-b6e9-ba56bfd287c3",
                  "service_id": "48d93535-3850-49b0-a81f-a1181c09d406",
                  "name": null,
                  "interface_type": "Protocols::Vpc",
                  "created_at": "2016-11-14T07:38:58.077Z",
                  "updated_at": "2016-11-14T07:38:58.077Z",
                  "depends": true,
                  "connections": [
                    {
                      "id": "308f36e8-0902-4328-b2af-6b8ce3d89770",
                      "interface_id": "8b6f05d1-6d30-4ecd-b6e9-ba56bfd287c3",
                      "remote_interface_id": "c6cb2561-6369-4bc4-98f4-4850ae2063a1",
                      "created_at": "2016-11-14T07:38:58.080Z",
                      "updated_at": "2016-11-14T07:38:58.080Z",
                      "internal": false
                    }
                  ]
                },
                {
                  "id": "5d01699b-18e7-489e-bf89-6e99fb16a245",
                  "service_id": "48d93535-3850-49b0-a81f-a1181c09d406",
                  "name": null,
                  "interface_type": "Protocols::AvailabilityZone",
                  "created_at": "2016-11-14T07:38:58.124Z",
                  "updated_at": "2016-11-14T07:38:58.124Z",
                  "depends": true,
                  "connections": [
                    {
                      "id": "e1fc3025-10f1-4441-ae28-609b0942764c",
                      "interface_id": "5d01699b-18e7-489e-bf89-6e99fb16a245",
                      "remote_interface_id": "65ff2a0f-0fd7-45a7-b34a-56dcfb1d7f67",
                      "created_at": "2016-11-14T07:38:58.127Z",
                      "updated_at": "2016-11-14T07:38:58.127Z",
                      "internal": false
                    }
                  ]
                }
              ],
              "name": "atit-subnet2",
              "state": "running",
              "primary_key": null,
              "properties": [
                {
                  "name": "cidr_block",
                  "value": "10.0.2.0/24",
                  "form_options": {
                    "type": "text"
                  },
                  "title": "CIDR Block"
                }
              ],
              "provides": [
                {
                  "name": "subnet",
                  "type": "Protocols::Subnet",
                  "properties": [
                    {
                      "name": "port",
                      "form_options": {
                        "type": "integer"
                      },
                      "title": "Port"
                    }
                  ]
                }
              ],
              "service_type": "subnet",
              "type": "Services::Network::Subnet::AWS",
              "vpc_id": "b4d269a4-49c5-42bf-b7c1-2818118fe6a7"
            },
            "tags": {
              "Name": "atit-subnet2"
            },
            "properties": [
              {
                "name": "cidr_block",
                "value": "10.0.2.0/24",
                "form_options": {
                  "type": "text"
                },
                "title": "CIDR Block"
              }
            ],
            "interfaces": [
              {
                "id": "31fe310c-eda2-4842-8c6d-f53cacf7257b",
                "name": "atit-subnet2",
                "interface_type": "Protocols::Subnet",
                "depends": false,
                "connections": []
              },
              {
                "id": "8b6f05d1-6d30-4ecd-b6e9-ba56bfd287c3",
                "interface_type": "Protocols::Vpc",
                "depends": true,
                "connections": [
                  {
                    "id": "308f36e8-0902-4328-b2af-6b8ce3d89770",
                    "interface_id": "8b6f05d1-6d30-4ecd-b6e9-ba56bfd287c3",
                    "remote_interface_id": "c6cb2561-6369-4bc4-98f4-4850ae2063a1",
                    "internal": false
                  }
                ]
              },
              {
                "id": "5d01699b-18e7-489e-bf89-6e99fb16a245",
                "interface_type": "Protocols::AvailabilityZone",
                "depends": true,
                "connections": [
                  {
                    "id": "e1fc3025-10f1-4441-ae28-609b0942764c",
                    "interface_id": "5d01699b-18e7-489e-bf89-6e99fb16a245",
                    "remote_interface_id": "65ff2a0f-0fd7-45a7-b34a-56dcfb1d7f67",
                    "internal": false
                  }
                ]
              }
            ],
            "provides": [
              {
                "name": "subnet",
                "type": "Protocols::Subnet",
                "properties": [
                  {
                    "name": "port",
                    "form_options": {
                      "type": "integer"
                    },
                    "title": "Port"
                  }
                ]
              }
            ],
            "depends": [
              {
                "name": "gateway",
                "type": "Protocols::IP",
                "properties": [
                  {
                    "name": "port",
                    "form_options": {
                      "type": "integer"
                    },
                    "title": "Port"
                  }
                ]
              }
            ]
          },
          {
            "id": "411b10b4-bb15-4650-bf67-2bf10c414580",
            "name": "atit-subnet1",
            "type": "Services::Network::Subnet::AWS",
            "container": true,
            "additional_properties": {
              "parent_id": "8529938f-1425-48d6-bd99-5d7736f20607",
              "depends": [
                {
                  "name": "gateway",
                  "protocol": {
                    "generated_attribute_methods": {
                      "_mutex": {}
                    },
                    "attribute_methods_generated": false,
                    "relation_delegate_cache": {
                      "ActiveRecord::Relation": {
                        "delegation_mutex": {}
                      },
                      "ActiveRecord::Associations::CollectionProxy": {
                        "delegation_mutex": {}
                      },
                      "ActiveRecord::AssociationRelation": {
                        "delegation_mutex": {}
                      }
                    }
                  }
                }
              ],
              "draggable": true,
              "drawable": true,
              "edge": false,
              "generic_type": "Services::Network::Subnet",
              "id": "411b10b4-bb15-4650-bf67-2bf10c414580",
              "interfaces": [
                {
                  "id": "39f5d424-f32f-42df-bb5f-db8dfb5a55e0",
                  "service_id": "411b10b4-bb15-4650-bf67-2bf10c414580",
                  "name": "atit-subnet1",
                  "interface_type": "Protocols::Subnet",
                  "created_at": "2016-11-14T07:38:52.311Z",
                  "updated_at": "2016-11-14T07:38:52.311Z",
                  "depends": false,
                  "connections": []
                },
                {
                  "id": "da3fc62a-3d93-4b18-acd9-a256fe7887be",
                  "service_id": "411b10b4-bb15-4650-bf67-2bf10c414580",
                  "name": null,
                  "interface_type": "Protocols::Vpc",
                  "created_at": "2016-11-14T07:38:58.266Z",
                  "updated_at": "2016-11-14T07:38:58.266Z",
                  "depends": true,
                  "connections": [
                    {
                      "id": "1f8ed63e-99ab-4ad1-818e-fadcdb4adce8",
                      "interface_id": "da3fc62a-3d93-4b18-acd9-a256fe7887be",
                      "remote_interface_id": "c6cb2561-6369-4bc4-98f4-4850ae2063a1",
                      "created_at": "2016-11-14T07:38:58.269Z",
                      "updated_at": "2016-11-14T07:38:58.269Z",
                      "internal": false
                    }
                  ]
                },
                {
                  "id": "65cd372e-9978-40d3-b97a-bf2e5b3bc4ea",
                  "service_id": "411b10b4-bb15-4650-bf67-2bf10c414580",
                  "name": null,
                  "interface_type": "Protocols::AvailabilityZone",
                  "created_at": "2016-11-14T07:38:58.314Z",
                  "updated_at": "2016-11-14T07:38:58.314Z",
                  "depends": true,
                  "connections": [
                    {
                      "id": "796f4a66-33ca-4774-9c93-ddc58c9561ac",
                      "interface_id": "65cd372e-9978-40d3-b97a-bf2e5b3bc4ea",
                      "remote_interface_id": "d7aabf56-bf06-4f95-8ee9-508311898e1e",
                      "created_at": "2016-11-14T07:38:58.318Z",
                      "updated_at": "2016-11-14T07:38:58.318Z",
                      "internal": false
                    }
                  ]
                }
              ],
              "name": "atit-subnet1",
              "state": "running",
              "primary_key": null,
              "properties": [
                {
                  "name": "cidr_block",
                  "value": "10.0.1.0/24",
                  "form_options": {
                    "type": "text"
                  },
                  "title": "CIDR Block"
                }
              ],
              "provides": [
                {
                  "name": "subnet",
                  "type": "Protocols::Subnet",
                  "properties": [
                    {
                      "name": "port",
                      "form_options": {
                        "type": "integer"
                      },
                      "title": "Port"
                    }
                  ]
                }
              ],
              "service_type": "subnet",
              "type": "Services::Network::Subnet::AWS",
              "vpc_id": "b4d269a4-49c5-42bf-b7c1-2818118fe6a7"
            },
            "tags": {
              "Name": "atit-subnet1"
            },
            "properties": [
              {
                "name": "cidr_block",
                "value": "10.0.1.0/24",
                "form_options": {
                  "type": "text"
                },
                "title": "CIDR Block"
              }
            ],
            "interfaces": [
              {
                "id": "39f5d424-f32f-42df-bb5f-db8dfb5a55e0",
                "name": "atit-subnet1",
                "interface_type": "Protocols::Subnet",
                "depends": false,
                "connections": []
              },
              {
                "id": "da3fc62a-3d93-4b18-acd9-a256fe7887be",
                "interface_type": "Protocols::Vpc",
                "depends": true,
                "connections": [
                  {
                    "id": "1f8ed63e-99ab-4ad1-818e-fadcdb4adce8",
                    "interface_id": "da3fc62a-3d93-4b18-acd9-a256fe7887be",
                    "remote_interface_id": "c6cb2561-6369-4bc4-98f4-4850ae2063a1",
                    "internal": false
                  }
                ]
              },
              {
                "id": "65cd372e-9978-40d3-b97a-bf2e5b3bc4ea",
                "interface_type": "Protocols::AvailabilityZone",
                "depends": true,
                "connections": [
                  {
                    "id": "796f4a66-33ca-4774-9c93-ddc58c9561ac",
                    "interface_id": "65cd372e-9978-40d3-b97a-bf2e5b3bc4ea",
                    "remote_interface_id": "d7aabf56-bf06-4f95-8ee9-508311898e1e",
                    "internal": false
                  }
                ]
              }
            ],
            "provides": [
              {
                "name": "subnet",
                "type": "Protocols::Subnet",
                "properties": [
                  {
                    "name": "port",
                    "form_options": {
                      "type": "integer"
                    },
                    "title": "Port"
                  }
                ]
              }
            ],
            "depends": [
              {
                "name": "gateway",
                "type": "Protocols::IP",
                "properties": [
                  {
                    "name": "port",
                    "form_options": {
                      "type": "integer"
                    },
                    "title": "Port"
                  }
                ]
              }
            ]
          },
          {
            "id": "ac98776e-2699-4721-a7ac-79d6f6ab8e2a",
            "name": "SN-V20-DEV-01",
            "type": "Services::Network::Subnet::AWS",
            "container": true,
            "additional_properties": {
              "parent_id": "8529938f-1425-48d6-bd99-5d7736f20607",
              "depends": [
                {
                  "name": "gateway",
                  "protocol": {
                    "generated_attribute_methods": {
                      "_mutex": {}
                    },
                    "attribute_methods_generated": false,
                    "relation_delegate_cache": {
                      "ActiveRecord::Relation": {
                        "delegation_mutex": {}
                      },
                      "ActiveRecord::Associations::CollectionProxy": {
                        "delegation_mutex": {}
                      },
                      "ActiveRecord::AssociationRelation": {
                        "delegation_mutex": {}
                      }
                    }
                  }
                }
              ],
              "draggable": true,
              "drawable": true,
              "edge": false,
              "generic_type": "Services::Network::Subnet",
              "id": "ac98776e-2699-4721-a7ac-79d6f6ab8e2a",
              "interfaces": [
                {
                  "id": "ea002968-b0b8-45d6-b5b9-b376eda43ecd",
                  "service_id": "ac98776e-2699-4721-a7ac-79d6f6ab8e2a",
                  "name": "SN-V20-DEV-01",
                  "interface_type": "Protocols::Subnet",
                  "created_at": "2016-11-14T07:38:52.267Z",
                  "updated_at": "2016-11-14T07:38:52.267Z",
                  "depends": false,
                  "connections": []
                },
                {
                  "id": "c05f8b8a-9106-46f0-aa3f-c3822176b32e",
                  "service_id": "ac98776e-2699-4721-a7ac-79d6f6ab8e2a",
                  "name": null,
                  "interface_type": "Protocols::Vpc",
                  "created_at": "2016-11-14T07:38:57.882Z",
                  "updated_at": "2016-11-14T07:38:57.882Z",
                  "depends": true,
                  "connections": [
                    {
                      "id": "8039bfe6-8301-4f94-838d-fbdba9a9fcbd",
                      "interface_id": "c05f8b8a-9106-46f0-aa3f-c3822176b32e",
                      "remote_interface_id": "c6cb2561-6369-4bc4-98f4-4850ae2063a1",
                      "created_at": "2016-11-14T07:38:57.885Z",
                      "updated_at": "2016-11-14T07:38:57.885Z",
                      "internal": false
                    }
                  ]
                },
                {
                  "id": "394b321c-a40b-4388-b8d3-b63611308247",
                  "service_id": "ac98776e-2699-4721-a7ac-79d6f6ab8e2a",
                  "name": null,
                  "interface_type": "Protocols::AvailabilityZone",
                  "created_at": "2016-11-14T07:38:57.932Z",
                  "updated_at": "2016-11-14T07:38:57.932Z",
                  "depends": true,
                  "connections": [
                    {
                      "id": "79e8e2c6-efac-4138-9404-cebcd58d6402",
                      "interface_id": "394b321c-a40b-4388-b8d3-b63611308247",
                      "remote_interface_id": "d7aabf56-bf06-4f95-8ee9-508311898e1e",
                      "created_at": "2016-11-14T07:38:57.935Z",
                      "updated_at": "2016-11-14T07:38:57.935Z",
                      "internal": false
                    }
                  ]
                }
              ],
              "name": "SN-V20-DEV-01",
              "state": "running",
              "primary_key": null,
              "properties": [
                {
                  "name": "cidr_block",
                  "value": "10.0.64.0/19",
                  "form_options": {
                    "type": "text"
                  },
                  "title": "CIDR Block"
                }
              ],
              "provides": [
                {
                  "name": "subnet",
                  "type": "Protocols::Subnet",
                  "properties": [
                    {
                      "name": "port",
                      "form_options": {
                        "type": "integer"
                      },
                      "title": "Port"
                    }
                  ]
                }
              ],
              "service_type": "subnet",
              "type": "Services::Network::Subnet::AWS",
              "vpc_id": "b4d269a4-49c5-42bf-b7c1-2818118fe6a7"
            },
            "tags": {
              "Name": "SN-V20-DEV-01"
            },
            "properties": [
              {
                "name": "cidr_block",
                "value": "10.0.64.0/19",
                "form_options": {
                  "type": "text"
                },
                "title": "CIDR Block"
              }
            ],
            "interfaces": [
              {
                "id": "ea002968-b0b8-45d6-b5b9-b376eda43ecd",
                "name": "SN-V20-DEV-01",
                "interface_type": "Protocols::Subnet",
                "depends": false,
                "connections": []
              },
              {
                "id": "c05f8b8a-9106-46f0-aa3f-c3822176b32e",
                "interface_type": "Protocols::Vpc",
                "depends": true,
                "connections": [
                  {
                    "id": "8039bfe6-8301-4f94-838d-fbdba9a9fcbd",
                    "interface_id": "c05f8b8a-9106-46f0-aa3f-c3822176b32e",
                    "remote_interface_id": "c6cb2561-6369-4bc4-98f4-4850ae2063a1",
                    "internal": false
                  }
                ]
              },
              {
                "id": "394b321c-a40b-4388-b8d3-b63611308247",
                "interface_type": "Protocols::AvailabilityZone",
                "depends": true,
                "connections": [
                  {
                    "id": "79e8e2c6-efac-4138-9404-cebcd58d6402",
                    "interface_id": "394b321c-a40b-4388-b8d3-b63611308247",
                    "remote_interface_id": "d7aabf56-bf06-4f95-8ee9-508311898e1e",
                    "internal": false
                  }
                ]
              }
            ],
            "provides": [
              {
                "name": "subnet",
                "type": "Protocols::Subnet",
                "properties": [
                  {
                    "name": "port",
                    "form_options": {
                      "type": "integer"
                    },
                    "title": "Port"
                  }
                ]
              }
            ],
            "depends": [
              {
                "name": "gateway",
                "type": "Protocols::IP",
                "properties": [
                  {
                    "name": "port",
                    "form_options": {
                      "type": "integer"
                    },
                    "title": "Port"
                  }
                ]
              }
            ]
          },
          {
            "id": "33371a94-acd7-4aeb-a235-d9378e38733f",
            "name": "atit-DB",
            "type": "Services::Compute::Server::AWS",
            "additional_properties": {
              "parent_id": "48d93535-3850-49b0-a81f-a1181c09d406",
              "depends": [
                {
                  "name": "network",
                  "protocol": {
                    "generated_attribute_methods": {
                      "_mutex": {}
                    },
                    "attribute_methods_generated": false,
                    "relation_delegate_cache": {
                      "ActiveRecord::Relation": {
                        "delegation_mutex": {}
                      },
                      "ActiveRecord::Associations::CollectionProxy": {
                        "delegation_mutex": {}
                      },
                      "ActiveRecord::AssociationRelation": {
                        "delegation_mutex": {}
                      }
                    },
                    "_store_accessors_module": {}
                  }
                },
                {
                  "name": "disk",
                  "protocol": {
                    "generated_attribute_methods": {
                      "_mutex": {}
                    },
                    "attribute_methods_generated": false,
                    "relation_delegate_cache": {
                      "ActiveRecord::Relation": {
                        "delegation_mutex": {}
                      },
                      "ActiveRecord::Associations::CollectionProxy": {
                        "delegation_mutex": {}
                      },
                      "ActiveRecord::AssociationRelation": {
                        "delegation_mutex": {}
                      }
                    },
                    "_store_accessors_module": {}
                  }
                },
                {
                  "name": "new_relic",
                  "protocol": {
                    "generated_attribute_methods": {
                      "_mutex": {}
                    },
                    "attribute_methods_generated": false,
                    "relation_delegate_cache": {
                      "ActiveRecord::Relation": {
                        "delegation_mutex": {}
                      },
                      "ActiveRecord::Associations::CollectionProxy": {
                        "delegation_mutex": {}
                      },
                      "ActiveRecord::AssociationRelation": {
                        "delegation_mutex": {}
                      }
                    }
                  }
                },
                {
                  "name": "TCP",
                  "protocol": {
                    "generated_attribute_methods": {
                      "_mutex": {}
                    },
                    "attribute_methods_generated": false,
                    "relation_delegate_cache": {
                      "ActiveRecord::Relation": {
                        "delegation_mutex": {}
                      },
                      "ActiveRecord::Associations::CollectionProxy": {
                        "delegation_mutex": {}
                      },
                      "ActiveRecord::AssociationRelation": {
                        "delegation_mutex": {}
                      }
                    }
                  }
                }
              ],
              "draggable": true,
              "drawable": true,
              "edge": false,
              "generic_type": "Services::Compute::Server",
              "id": "33371a94-acd7-4aeb-a235-d9378e38733f",
              "interfaces": [
                {
                  "id": "25624f35-7e7f-427d-a4b5-41351733effd",
                  "service_id": "33371a94-acd7-4aeb-a235-d9378e38733f",
                  "name": "atit-DB",
                  "interface_type": "Protocols::Server",
                  "created_at": "2016-11-14T07:38:52.358Z",
                  "updated_at": "2016-11-14T07:38:52.358Z",
                  "depends": false,
                  "connections": []
                },
                {
                  "id": "20fb9fc8-017c-4c9b-a4b0-8c54de160af7",
                  "service_id": "33371a94-acd7-4aeb-a235-d9378e38733f",
                  "name": null,
                  "interface_type": "Protocols::Vpc",
                  "created_at": "2016-11-14T07:38:58.788Z",
                  "updated_at": "2016-11-14T07:38:58.788Z",
                  "depends": true,
                  "connections": [
                    {
                      "id": "f6193230-d58c-49e7-8905-ccc48499a034",
                      "interface_id": "20fb9fc8-017c-4c9b-a4b0-8c54de160af7",
                      "remote_interface_id": "c6cb2561-6369-4bc4-98f4-4850ae2063a1",
                      "created_at": "2016-11-14T07:38:58.791Z",
                      "updated_at": "2016-11-14T07:38:58.791Z",
                      "internal": false
                    }
                  ]
                },
                {
                  "id": "b28128dd-e330-41ed-94a8-3d22f0a92200",
                  "service_id": "33371a94-acd7-4aeb-a235-d9378e38733f",
                  "name": null,
                  "interface_type": "Protocols::Subnet",
                  "created_at": "2016-11-14T07:38:58.836Z",
                  "updated_at": "2016-11-14T07:38:58.836Z",
                  "depends": true,
                  "connections": [
                    {
                      "id": "6376e5b9-2a04-4788-96b9-c202ae0f9f0b",
                      "interface_id": "b28128dd-e330-41ed-94a8-3d22f0a92200",
                      "remote_interface_id": "31fe310c-eda2-4842-8c6d-f53cacf7257b",
                      "created_at": "2016-11-14T07:38:58.839Z",
                      "updated_at": "2016-11-14T07:38:58.839Z",
                      "internal": false
                    }
                  ]
                },
                {
                  "id": "53d3fef2-3dd5-4b74-9d12-0f9e5766319c",
                  "service_id": "33371a94-acd7-4aeb-a235-d9378e38733f",
                  "name": null,
                  "interface_type": "Protocols::SecurityGroup",
                  "created_at": "2016-11-14T07:38:58.882Z",
                  "updated_at": "2016-11-14T07:38:58.882Z",
                  "depends": true,
                  "connections": [
                    {
                      "id": "9fc30337-5b3f-474d-95b1-43fb8d3d732e",
                      "interface_id": "53d3fef2-3dd5-4b74-9d12-0f9e5766319c",
                      "remote_interface_id": "a017f658-0f50-4d44-a7eb-b6b581d24d64",
                      "created_at": "2016-11-14T07:38:58.885Z",
                      "updated_at": "2016-11-14T07:38:58.885Z",
                      "internal": false
                    }
                  ]
                },
                {
                  "id": "f6813db3-005c-4568-ae67-fabbc3c7ed47",
                  "service_id": "33371a94-acd7-4aeb-a235-d9378e38733f",
                  "name": null,
                  "interface_type": "Protocols::Disk",
                  "created_at": "2016-11-14T07:38:58.930Z",
                  "updated_at": "2016-11-14T07:38:58.930Z",
                  "depends": true,
                  "connections": [
                    {
                      "id": "64ab164a-52dd-4645-b01c-9e7cbf4a6e99",
                      "interface_id": "f6813db3-005c-4568-ae67-fabbc3c7ed47",
                      "remote_interface_id": "9d72a453-f282-4a5c-a171-223725c77d8c",
                      "created_at": "2016-11-14T07:38:58.933Z",
                      "updated_at": "2016-11-14T07:38:58.933Z",
                      "internal": false
                    }
                  ]
                }
              ],
              "name": "atit-DB",
              "state": "running",
              "primary_key": null,
              "properties": [
                {
                  "name": "root_device_name",
                  "form_options": {
                    "type": "hidden",
                    "readonly": "readonly"
                  },
                  "title": "Root Device Name"
                },
                {
                  "name": "image_id",
                  "value": "ami-b953f2da",
                  "form_options": {
                    "type": "text",
                    "readonly": "readonly"
                  },
                  "title": "AMI"
                },
                {
                  "name": "image_config_id",
                  "form_options": {
                    "type": "select",
                    "options": {
                      "57c023dd-036c-4684-9e99-a384f54e7f30": "Default"
                    },
                    "flavor_ids": {
                      "57c023dd-036c-4684-9e99-a384f54e7f30": [
                        "t2.nano",
                        "t1.micro",
                        "t2.micro",
                        "t2.small",
                        "t2.medium",
                        "m3.medium",
                        "m3.large",
                        "m3.xlarge",
                        "m3.2xlarge",
                        "m1.small",
                        "m1.medium",
                        "m1.large",
                        "m1.xlarge",
                        "c3.large",
                        "c3.xlarge",
                        "c3.2xlarge",
                        "c3.4xlarge",
                        "c3.8xlarge",
                        "c1.medium",
                        "c1.xlarge",
                        "cc2.8xlarge",
                        "cc1.4xlarge",
                        "g2.2xlarge",
                        "cg1.4xlarge",
                        "r3.large",
                        "r3.xlarge",
                        "r3.2xlarge",
                        "r3.4xlarge",
                        "r3.8xlarge",
                        "m2.xlarge",
                        "m2.2xlarge",
                        "m2.4xlarge",
                        "cr1.8xlarge",
                        "i2.xlarge",
                        "i2.2xlarge",
                        "i2.4xlarge",
                        "i2.8xlarge",
                        "hi1.4xlarge",
                        "hs1.8xlarge"
                      ]
                    },
                    "required": true
                  },
                  "title": "AMI Configurations"
                },
                {
                  "name": "img_archived",
                  "form_options": {
                    "type": "hidden",
                    "readonly": "readonly"
                  },
                  "title": "img_archived"
                },
                {
                  "name": "flavor_id",
                  "value": "t2.micro",
                  "form_options": {
                    "type": "select",
                    "options": [
                      "t2.nano",
                      "t1.micro",
                      "t2.micro",
                      "t2.small",
                      "t2.medium",
                      "m3.medium",
                      "m3.large",
                      "m3.xlarge",
                      "m3.2xlarge",
                      "m1.small",
                      "m1.medium",
                      "m1.large",
                      "m1.xlarge",
                      "c3.large",
                      "c3.xlarge",
                      "c3.2xlarge",
                      "c3.4xlarge",
                      "c3.8xlarge",
                      "c1.medium",
                      "c1.xlarge",
                      "cc2.8xlarge",
                      "cc1.4xlarge",
                      "g2.2xlarge",
                      "cg1.4xlarge",
                      "r3.large",
                      "r3.xlarge",
                      "r3.2xlarge",
                      "r3.4xlarge",
                      "r3.8xlarge",
                      "m2.xlarge",
                      "m2.2xlarge",
                      "m2.4xlarge",
                      "cr1.8xlarge",
                      "i2.xlarge",
                      "i2.2xlarge",
                      "i2.4xlarge",
                      "i2.8xlarge",
                      "hi1.4xlarge",
                      "hs1.8xlarge"
                    ],
                    "required": true
                  },
                  "title": "Instance Type"
                },
                {
                  "name": "source_dest_check",
                  "value": true,
                  "form_options": {
                    "type": "checkbox",
                    "required": false
                  },
                  "title": "Source Destination Check"
                },
                {
                  "name": "private_ip_address",
                  "value": "10.0.2.190",
                  "form_options": {
                    "type": "hidden",
                    "required": false
                  },
                  "title": "Private IP Address"
                },
                {
                  "name": "virtualization_type",
                  "value": "hvm",
                  "form_options": {
                    "type": "hidden",
                    "readonly": "readonly"
                  },
                  "title": "Virtualization Type"
                },
                {
                  "name": "root_device_type",
                  "value": "ebs",
                  "form_options": {
                    "type": "hidden",
                    "readonly": "readonly"
                  },
                  "title": "Root Device Type"
                },
                {
                  "name": "platform",
                  "value": "non-windows",
                  "form_options": {
                    "type": "hidden",
                    "readonly": "readonly"
                  },
                  "title": "Platform"
                },
                {
                  "name": "associate_public_ip",
                  "form_options": {
                    "type": "checkbox",
                    "required": false
                  },
                  "title": "Associate Public IP"
                },
                {
                  "name": "block_device_mappings",
                  "form_options": {
                    "type": "block_device_mappings"
                  },
                  "title": "Block Device Mappings"
                },
                {
                  "name": "ebs_optimized",
                  "form_options": {
                    "type": "checkbox",
                    "required": false
                  },
                  "title": "EBS Optimized"
                },
                {
                  "name": "instance_monitoring",
                  "form_options": {
                    "type": "checkbox",
                    "required": false
                  },
                  "title": "Instance Monitoring"
                },
                {
                  "name": "key_name",
                  "value": "atit-singapore-VPC",
                  "form_options": {
                    "type": "select",
                    "options": [],
                    "data": [],
                    "keepfirstblank": true,
                    "firstblanktext": "Proceed without a Key Pair"
                  },
                  "title": "Select Key Pair"
                },
                {
                  "name": "original_block_device_mappings",
                  "value": [
                    {
                      "Ebs.VolumeSize": "8",
                      "Ebs.VolumeType": "gp2",
                      "Ebs.Iops": "24",
                      "Ebs.DeleteOnTermination": true,
                      "DevicePath": "/dev/xvda",
                      "Ebs.encrypted": false
                    }
                  ],
                  "form_options": {
                    "type": "hidden",
                    "readonly": "readonly"
                  },
                  "title": "Original Block Device Mappings"
                },
                {
                  "name": "filer_ids",
                  "value": [],
                  "form_options": {
                    "type": "hidden",
                    "readonly": "readonly",
                    "options": [],
                    "required": false
                  },
                  "title": "Filer Ids"
                },
                {
                  "name": "instance_initiated_shutdown_behavior",
                  "value": "stop",
                  "form_options": {
                    "type": "select",
                    "options": [
                      "stop",
                      "terminate"
                    ],
                    "required": true
                  },
                  "title": "Instance Initiated Shutdown Behavior"
                },
                {
                  "name": "iam_role",
                  "form_options": {
                    "type": "select",
                    "options": [
                      null
                    ],
                    "keepfirstblank": true,
                    "firstblanktext": "None",
                    "required": false
                  },
                  "title": "IAM Role"
                }
              ],
              "provides": [
                {
                  "name": "server",
                  "type": "Protocols::Server",
                  "properties": [
                    {
                      "name": "port",
                      "form_options": {
                        "type": "integer"
                      },
                      "title": "Port"
                    }
                  ]
                }
              ],
              "service_type": "server",
              "type": "Services::Compute::Server::AWS",
              "vpc_id": "b4d269a4-49c5-42bf-b7c1-2818118fe6a7"
            },
            "tags": {
              "Name": "atit-DB"
            },
            "display_name": "",
            "properties": [
              {
                "name": "root_device_name",
                "form_options": {
                  "type": "hidden",
                  "readonly": "readonly"
                },
                "title": "Root Device Name"
              },
              {
                "name": "image_id",
                "value": "ami-b953f2da",
                "form_options": {
                  "type": "text",
                  "readonly": "readonly"
                },
                "title": "AMI"
              },
              {
                "name": "image_config_id",
                "form_options": {
                  "type": "select",
                  "options": {
                    "57c023dd-036c-4684-9e99-a384f54e7f30": "Default"
                  },
                  "flavor_ids": {
                    "57c023dd-036c-4684-9e99-a384f54e7f30": [
                      "t2.nano",
                      "t1.micro",
                      "t2.micro",
                      "t2.small",
                      "t2.medium",
                      "m3.medium",
                      "m3.large",
                      "m3.xlarge",
                      "m3.2xlarge",
                      "m1.small",
                      "m1.medium",
                      "m1.large",
                      "m1.xlarge",
                      "c3.large",
                      "c3.xlarge",
                      "c3.2xlarge",
                      "c3.4xlarge",
                      "c3.8xlarge",
                      "c1.medium",
                      "c1.xlarge",
                      "cc2.8xlarge",
                      "cc1.4xlarge",
                      "g2.2xlarge",
                      "cg1.4xlarge",
                      "r3.large",
                      "r3.xlarge",
                      "r3.2xlarge",
                      "r3.4xlarge",
                      "r3.8xlarge",
                      "m2.xlarge",
                      "m2.2xlarge",
                      "m2.4xlarge",
                      "cr1.8xlarge",
                      "i2.xlarge",
                      "i2.2xlarge",
                      "i2.4xlarge",
                      "i2.8xlarge",
                      "hi1.4xlarge",
                      "hs1.8xlarge"
                    ]
                  },
                  "required": true
                },
                "title": "AMI Configurations"
              },
              {
                "name": "img_archived",
                "form_options": {
                  "type": "hidden",
                  "readonly": "readonly"
                },
                "title": "img_archived"
              },
              {
                "name": "flavor_id",
                "value": "t2.micro",
                "form_options": {
                  "type": "select",
                  "options": [
                    "t2.nano",
                    "t1.micro",
                    "t2.micro",
                    "t2.small",
                    "t2.medium",
                    "m3.medium",
                    "m3.large",
                    "m3.xlarge",
                    "m3.2xlarge",
                    "m1.small",
                    "m1.medium",
                    "m1.large",
                    "m1.xlarge",
                    "c3.large",
                    "c3.xlarge",
                    "c3.2xlarge",
                    "c3.4xlarge",
                    "c3.8xlarge",
                    "c1.medium",
                    "c1.xlarge",
                    "cc2.8xlarge",
                    "cc1.4xlarge",
                    "g2.2xlarge",
                    "cg1.4xlarge",
                    "r3.large",
                    "r3.xlarge",
                    "r3.2xlarge",
                    "r3.4xlarge",
                    "r3.8xlarge",
                    "m2.xlarge",
                    "m2.2xlarge",
                    "m2.4xlarge",
                    "cr1.8xlarge",
                    "i2.xlarge",
                    "i2.2xlarge",
                    "i2.4xlarge",
                    "i2.8xlarge",
                    "hi1.4xlarge",
                    "hs1.8xlarge"
                  ],
                  "required": true
                },
                "title": "Instance Type"
              },
              {
                "name": "source_dest_check",
                "value": true,
                "form_options": {
                  "type": "checkbox",
                  "required": false
                },
                "title": "Source Destination Check"
              },
              {
                "name": "private_ip_address",
                "value": "10.0.2.190",
                "form_options": {
                  "type": "hidden",
                  "required": false
                },
                "title": "Private IP Address"
              },
              {
                "name": "virtualization_type",
                "value": "hvm",
                "form_options": {
                  "type": "hidden",
                  "readonly": "readonly"
                },
                "title": "Virtualization Type"
              },
              {
                "name": "root_device_type",
                "value": "ebs",
                "form_options": {
                  "type": "hidden",
                  "readonly": "readonly"
                },
                "title": "Root Device Type"
              },
              {
                "name": "platform",
                "value": "non-windows",
                "form_options": {
                  "type": "hidden",
                  "readonly": "readonly"
                },
                "title": "Platform"
              },
              {
                "name": "associate_public_ip",
                "form_options": {
                  "type": "checkbox",
                  "required": false
                },
                "title": "Associate Public IP"
              },
              {
                "name": "block_device_mappings",
                "form_options": {
                  "type": "block_device_mappings"
                },
                "title": "Block Device Mappings"
              },
              {
                "name": "ebs_optimized",
                "form_options": {
                  "type": "checkbox",
                  "required": false
                },
                "title": "EBS Optimized"
              },
              {
                "name": "instance_monitoring",
                "form_options": {
                  "type": "checkbox",
                  "required": false
                },
                "title": "Instance Monitoring"
              },
              {
                "name": "key_name",
                "value": "atit-singapore-VPC",
                "form_options": {
                  "type": "select",
                  "options": [],
                  "data": [],
                  "keepfirstblank": true,
                  "firstblanktext": "Proceed without a Key Pair"
                },
                "title": "Select Key Pair"
              },
              {
                "name": "original_block_device_mappings",
                "value": [
                  {
                    "Ebs.VolumeSize": "8",
                    "Ebs.VolumeType": "gp2",
                    "Ebs.Iops": "24",
                    "Ebs.DeleteOnTermination": true,
                    "DevicePath": "/dev/xvda",
                    "Ebs.encrypted": false
                  }
                ],
                "form_options": {
                  "type": "hidden",
                  "readonly": "readonly"
                },
                "title": "Original Block Device Mappings"
              },
              {
                "name": "filer_ids",
                "value": [],
                "form_options": {
                  "type": "hidden",
                  "readonly": "readonly",
                  "options": [],
                  "required": false
                },
                "title": "Filer Ids"
              },
              {
                "name": "instance_initiated_shutdown_behavior",
                "value": "stop",
                "form_options": {
                  "type": "select",
                  "options": [
                    "stop",
                    "terminate"
                  ],
                  "required": true
                },
                "title": "Instance Initiated Shutdown Behavior"
              },
              {
                "name": "iam_role",
                "form_options": {
                  "type": "select",
                  "options": [
                    null
                  ],
                  "keepfirstblank": true,
                  "firstblanktext": "None",
                  "required": false
                },
                "title": "IAM Role"
              }
            ],
            "interfaces": [
              {
                "id": "25624f35-7e7f-427d-a4b5-41351733effd",
                "name": "atit-DB",
                "interface_type": "Protocols::Server",
                "depends": false,
                "connections": []
              },
              {
                "id": "20fb9fc8-017c-4c9b-a4b0-8c54de160af7",
                "interface_type": "Protocols::Vpc",
                "depends": true,
                "connections": [
                  {
                    "id": "f6193230-d58c-49e7-8905-ccc48499a034",
                    "interface_id": "20fb9fc8-017c-4c9b-a4b0-8c54de160af7",
                    "remote_interface_id": "c6cb2561-6369-4bc4-98f4-4850ae2063a1",
                    "internal": false
                  }
                ]
              },
              {
                "id": "b28128dd-e330-41ed-94a8-3d22f0a92200",
                "interface_type": "Protocols::Subnet",
                "depends": true,
                "connections": [
                  {
                    "id": "6376e5b9-2a04-4788-96b9-c202ae0f9f0b",
                    "interface_id": "b28128dd-e330-41ed-94a8-3d22f0a92200",
                    "remote_interface_id": "31fe310c-eda2-4842-8c6d-f53cacf7257b",
                    "internal": false
                  }
                ]
              },
              {
                "id": "53d3fef2-3dd5-4b74-9d12-0f9e5766319c",
                "interface_type": "Protocols::SecurityGroup",
                "depends": true,
                "connections": [
                  {
                    "id": "9fc30337-5b3f-474d-95b1-43fb8d3d732e",
                    "interface_id": "53d3fef2-3dd5-4b74-9d12-0f9e5766319c",
                    "remote_interface_id": "a017f658-0f50-4d44-a7eb-b6b581d24d64",
                    "internal": false
                  }
                ]
              },
              {
                "id": "f6813db3-005c-4568-ae67-fabbc3c7ed47",
                "interface_type": "Protocols::Disk",
                "depends": true,
                "connections": [
                  {
                    "id": "64ab164a-52dd-4645-b01c-9e7cbf4a6e99",
                    "interface_id": "f6813db3-005c-4568-ae67-fabbc3c7ed47",
                    "remote_interface_id": "9d72a453-f282-4a5c-a171-223725c77d8c",
                    "internal": false
                  }
                ]
              }
            ],
            "provides": [
              {
                "name": "server",
                "type": "Protocols::Server",
                "properties": [
                  {
                    "name": "port",
                    "form_options": {
                      "type": "integer"
                    },
                    "title": "Port"
                  }
                ]
              }
            ],
            "depends": [
              {
                "name": "network",
                "type": "Protocols::Network",
                "properties": [
                  {
                    "name": "port",
                    "form_options": {
                      "type": "integer"
                    },
                    "title": "Port"
                  }
                ]
              },
              {
                "name": "disk",
                "type": "Protocols::Disk",
                "properties": [
                  {
                    "name": "port",
                    "form_options": {
                      "type": "integer"
                    },
                    "title": "Port"
                  }
                ]
              },
              {
                "name": "new_relic",
                "type": "Protocols::NewRelic",
                "properties": [
                  {
                    "name": "port",
                    "form_options": {
                      "type": "integer"
                    },
                    "title": "Port"
                  }
                ]
              },
              {
                "name": "TCP",
                "type": "Protocols::IP::TCP",
                "properties": [
                  {
                    "name": "port",
                    "form_options": {
                      "type": "integer"
                    },
                    "title": "Port"
                  }
                ]
              }
            ]
          },
          {
            "id": "c7a915a6-8aac-46f2-898c-16f05b06e9f1",
            "name": "atit-AWS-Linux",
            "type": "Services::Compute::Server::AWS",
            "additional_properties": {
              "parent_id": "411b10b4-bb15-4650-bf67-2bf10c414580",
              "depends": [
                {
                  "name": "network",
                  "protocol": {
                    "generated_attribute_methods": {
                      "_mutex": {}
                    },
                    "attribute_methods_generated": false,
                    "relation_delegate_cache": {
                      "ActiveRecord::Relation": {
                        "delegation_mutex": {}
                      },
                      "ActiveRecord::Associations::CollectionProxy": {
                        "delegation_mutex": {}
                      },
                      "ActiveRecord::AssociationRelation": {
                        "delegation_mutex": {}
                      }
                    },
                    "_store_accessors_module": {}
                  }
                },
                {
                  "name": "disk",
                  "protocol": {
                    "generated_attribute_methods": {
                      "_mutex": {}
                    },
                    "attribute_methods_generated": false,
                    "relation_delegate_cache": {
                      "ActiveRecord::Relation": {
                        "delegation_mutex": {}
                      },
                      "ActiveRecord::Associations::CollectionProxy": {
                        "delegation_mutex": {}
                      },
                      "ActiveRecord::AssociationRelation": {
                        "delegation_mutex": {}
                      }
                    },
                    "_store_accessors_module": {}
                  }
                },
                {
                  "name": "new_relic",
                  "protocol": {
                    "generated_attribute_methods": {
                      "_mutex": {}
                    },
                    "attribute_methods_generated": false,
                    "relation_delegate_cache": {
                      "ActiveRecord::Relation": {
                        "delegation_mutex": {}
                      },
                      "ActiveRecord::Associations::CollectionProxy": {
                        "delegation_mutex": {}
                      },
                      "ActiveRecord::AssociationRelation": {
                        "delegation_mutex": {}
                      }
                    }
                  }
                },
                {
                  "name": "TCP",
                  "protocol": {
                    "generated_attribute_methods": {
                      "_mutex": {}
                    },
                    "attribute_methods_generated": false,
                    "relation_delegate_cache": {
                      "ActiveRecord::Relation": {
                        "delegation_mutex": {}
                      },
                      "ActiveRecord::Associations::CollectionProxy": {
                        "delegation_mutex": {}
                      },
                      "ActiveRecord::AssociationRelation": {
                        "delegation_mutex": {}
                      }
                    }
                  }
                }
              ],
              "draggable": true,
              "drawable": true,
              "edge": false,
              "generic_type": "Services::Compute::Server",
              "id": "c7a915a6-8aac-46f2-898c-16f05b06e9f1",
              "interfaces": [
                {
                  "id": "8dd5b33d-2557-48a9-8d9d-54d6d4394203",
                  "service_id": "c7a915a6-8aac-46f2-898c-16f05b06e9f1",
                  "name": "atit-AWS-Linux",
                  "interface_type": "Protocols::Server",
                  "created_at": "2016-11-14T07:38:52.334Z",
                  "updated_at": "2016-11-14T07:38:52.334Z",
                  "depends": false,
                  "connections": []
                },
                {
                  "id": "8b4f13cb-279e-4935-86cd-c9e220f88c9a",
                  "service_id": "c7a915a6-8aac-46f2-898c-16f05b06e9f1",
                  "name": null,
                  "interface_type": "Protocols::Vpc",
                  "created_at": "2016-11-14T07:38:58.454Z",
                  "updated_at": "2016-11-14T07:38:58.454Z",
                  "depends": true,
                  "connections": [
                    {
                      "id": "71eb8488-952a-4c4e-9cae-1049ddcaa6f0",
                      "interface_id": "8b4f13cb-279e-4935-86cd-c9e220f88c9a",
                      "remote_interface_id": "c6cb2561-6369-4bc4-98f4-4850ae2063a1",
                      "created_at": "2016-11-14T07:38:58.457Z",
                      "updated_at": "2016-11-14T07:38:58.457Z",
                      "internal": false
                    }
                  ]
                },
                {
                  "id": "5bf63fc7-1266-4329-a34a-67bb1360e944",
                  "service_id": "c7a915a6-8aac-46f2-898c-16f05b06e9f1",
                  "name": null,
                  "interface_type": "Protocols::Subnet",
                  "created_at": "2016-11-14T07:38:58.501Z",
                  "updated_at": "2016-11-14T07:38:58.501Z",
                  "depends": true,
                  "connections": [
                    {
                      "id": "b3328c5c-0def-470c-998c-5830521b89be",
                      "interface_id": "5bf63fc7-1266-4329-a34a-67bb1360e944",
                      "remote_interface_id": "39f5d424-f32f-42df-bb5f-db8dfb5a55e0",
                      "created_at": "2016-11-14T07:38:58.504Z",
                      "updated_at": "2016-11-14T07:38:58.504Z",
                      "internal": false
                    }
                  ]
                },
                {
                  "id": "5262e316-cc94-408d-92fa-7cd8c7435826",
                  "service_id": "c7a915a6-8aac-46f2-898c-16f05b06e9f1",
                  "name": null,
                  "interface_type": "Protocols::SecurityGroup",
                  "created_at": "2016-11-14T07:38:58.550Z",
                  "updated_at": "2016-11-14T07:38:58.550Z",
                  "depends": true,
                  "connections": [
                    {
                      "id": "47b90d3e-e67e-4f95-8af8-1af5b27d196b",
                      "interface_id": "5262e316-cc94-408d-92fa-7cd8c7435826",
                      "remote_interface_id": "5b972c87-9b50-437e-b83a-429edb2cb043",
                      "created_at": "2016-11-14T07:38:58.553Z",
                      "updated_at": "2016-11-14T07:38:58.553Z",
                      "internal": false
                    }
                  ]
                },
                {
                  "id": "024ef624-2b09-4143-9d7f-b2ee42ccfa84",
                  "service_id": "c7a915a6-8aac-46f2-898c-16f05b06e9f1",
                  "name": null,
                  "interface_type": "Protocols::Disk",
                  "created_at": "2016-11-14T07:38:58.599Z",
                  "updated_at": "2016-11-14T07:38:58.599Z",
                  "depends": true,
                  "connections": [
                    {
                      "id": "4a61337d-9e45-440f-8d1e-638c00efd117",
                      "interface_id": "024ef624-2b09-4143-9d7f-b2ee42ccfa84",
                      "remote_interface_id": "fe55c334-3569-475c-a306-4f1f8b14428a",
                      "created_at": "2016-11-14T07:38:58.602Z",
                      "updated_at": "2016-11-14T07:38:58.602Z",
                      "internal": false
                    }
                  ]
                }
              ],
              "name": "atit-AWS-Linux",
              "state": "running",
              "primary_key": null,
              "properties": [
                {
                  "name": "root_device_name",
                  "form_options": {
                    "type": "hidden",
                    "readonly": "readonly"
                  },
                  "title": "Root Device Name"
                },
                {
                  "name": "image_id",
                  "value": "ami-b953f2da",
                  "form_options": {
                    "type": "text",
                    "readonly": "readonly"
                  },
                  "title": "AMI"
                },
                {
                  "name": "image_config_id",
                  "form_options": {
                    "type": "select",
                    "options": {
                      "57c023dd-036c-4684-9e99-a384f54e7f30": "Default"
                    },
                    "flavor_ids": {
                      "57c023dd-036c-4684-9e99-a384f54e7f30": [
                        "t2.nano",
                        "t1.micro",
                        "t2.micro",
                        "t2.small",
                        "t2.medium",
                        "m3.medium",
                        "m3.large",
                        "m3.xlarge",
                        "m3.2xlarge",
                        "m1.small",
                        "m1.medium",
                        "m1.large",
                        "m1.xlarge",
                        "c3.large",
                        "c3.xlarge",
                        "c3.2xlarge",
                        "c3.4xlarge",
                        "c3.8xlarge",
                        "c1.medium",
                        "c1.xlarge",
                        "cc2.8xlarge",
                        "cc1.4xlarge",
                        "g2.2xlarge",
                        "cg1.4xlarge",
                        "r3.large",
                        "r3.xlarge",
                        "r3.2xlarge",
                        "r3.4xlarge",
                        "r3.8xlarge",
                        "m2.xlarge",
                        "m2.2xlarge",
                        "m2.4xlarge",
                        "cr1.8xlarge",
                        "i2.xlarge",
                        "i2.2xlarge",
                        "i2.4xlarge",
                        "i2.8xlarge",
                        "hi1.4xlarge",
                        "hs1.8xlarge"
                      ]
                    },
                    "required": true
                  },
                  "title": "AMI Configurations"
                },
                {
                  "name": "img_archived",
                  "form_options": {
                    "type": "hidden",
                    "readonly": "readonly"
                  },
                  "title": "img_archived"
                },
                {
                  "name": "flavor_id",
                  "value": "t2.micro",
                  "form_options": {
                    "type": "select",
                    "options": [
                      "t2.nano",
                      "t1.micro",
                      "t2.micro",
                      "t2.small",
                      "t2.medium",
                      "m3.medium",
                      "m3.large",
                      "m3.xlarge",
                      "m3.2xlarge",
                      "m1.small",
                      "m1.medium",
                      "m1.large",
                      "m1.xlarge",
                      "c3.large",
                      "c3.xlarge",
                      "c3.2xlarge",
                      "c3.4xlarge",
                      "c3.8xlarge",
                      "c1.medium",
                      "c1.xlarge",
                      "cc2.8xlarge",
                      "cc1.4xlarge",
                      "g2.2xlarge",
                      "cg1.4xlarge",
                      "r3.large",
                      "r3.xlarge",
                      "r3.2xlarge",
                      "r3.4xlarge",
                      "r3.8xlarge",
                      "m2.xlarge",
                      "m2.2xlarge",
                      "m2.4xlarge",
                      "cr1.8xlarge",
                      "i2.xlarge",
                      "i2.2xlarge",
                      "i2.4xlarge",
                      "i2.8xlarge",
                      "hi1.4xlarge",
                      "hs1.8xlarge"
                    ],
                    "required": true
                  },
                  "title": "Instance Type"
                },
                {
                  "name": "source_dest_check",
                  "value": true,
                  "form_options": {
                    "type": "checkbox",
                    "required": false
                  },
                  "title": "Source Destination Check"
                },
                {
                  "name": "private_ip_address",
                  "value": "10.0.1.237",
                  "form_options": {
                    "type": "hidden",
                    "required": false
                  },
                  "title": "Private IP Address"
                },
                {
                  "name": "virtualization_type",
                  "value": "hvm",
                  "form_options": {
                    "type": "hidden",
                    "readonly": "readonly"
                  },
                  "title": "Virtualization Type"
                },
                {
                  "name": "root_device_type",
                  "value": "ebs",
                  "form_options": {
                    "type": "hidden",
                    "readonly": "readonly"
                  },
                  "title": "Root Device Type"
                },
                {
                  "name": "platform",
                  "value": "non-windows",
                  "form_options": {
                    "type": "hidden",
                    "readonly": "readonly"
                  },
                  "title": "Platform"
                },
                {
                  "name": "associate_public_ip",
                  "form_options": {
                    "type": "checkbox",
                    "required": false
                  },
                  "title": "Associate Public IP"
                },
                {
                  "name": "block_device_mappings",
                  "form_options": {
                    "type": "block_device_mappings"
                  },
                  "title": "Block Device Mappings"
                },
                {
                  "name": "ebs_optimized",
                  "form_options": {
                    "type": "checkbox",
                    "required": false
                  },
                  "title": "EBS Optimized"
                },
                {
                  "name": "instance_monitoring",
                  "form_options": {
                    "type": "checkbox",
                    "required": false
                  },
                  "title": "Instance Monitoring"
                },
                {
                  "name": "key_name",
                  "value": "atit-singapore-VPC",
                  "form_options": {
                    "type": "select",
                    "options": [],
                    "data": [],
                    "keepfirstblank": true,
                    "firstblanktext": "Proceed without a Key Pair"
                  },
                  "title": "Select Key Pair"
                },
                {
                  "name": "original_block_device_mappings",
                  "value": [
                    {
                      "Ebs.VolumeSize": "8",
                      "Ebs.VolumeType": "gp2",
                      "Ebs.Iops": "24",
                      "Ebs.DeleteOnTermination": true,
                      "DevicePath": "/dev/xvda",
                      "Ebs.encrypted": false
                    }
                  ],
                  "form_options": {
                    "type": "hidden",
                    "readonly": "readonly"
                  },
                  "title": "Original Block Device Mappings"
                },
                {
                  "name": "filer_ids",
                  "value": [],
                  "form_options": {
                    "type": "hidden",
                    "readonly": "readonly",
                    "options": [],
                    "required": false
                  },
                  "title": "Filer Ids"
                },
                {
                  "name": "instance_initiated_shutdown_behavior",
                  "value": "stop",
                  "form_options": {
                    "type": "select",
                    "options": [
                      "stop",
                      "terminate"
                    ],
                    "required": true
                  },
                  "title": "Instance Initiated Shutdown Behavior"
                },
                {
                  "name": "iam_role",
                  "form_options": {
                    "type": "select",
                    "options": [
                      null
                    ],
                    "keepfirstblank": true,
                    "firstblanktext": "None",
                    "required": false
                  },
                  "title": "IAM Role"
                }
              ],
              "provides": [
                {
                  "name": "server",
                  "type": "Protocols::Server",
                  "properties": [
                    {
                      "name": "port",
                      "form_options": {
                        "type": "integer"
                      },
                      "title": "Port"
                    }
                  ]
                }
              ],
              "service_type": "server",
              "type": "Services::Compute::Server::AWS",
              "vpc_id": "b4d269a4-49c5-42bf-b7c1-2818118fe6a7"
            },
            "tags": {
              "Name": "atit-AWS-Linux"
            },
            "display_name": "",
            "properties": [
              {
                "name": "root_device_name",
                "form_options": {
                  "type": "hidden",
                  "readonly": "readonly"
                },
                "title": "Root Device Name"
              },
              {
                "name": "image_id",
                "value": "ami-b953f2da",
                "form_options": {
                  "type": "text",
                  "readonly": "readonly"
                },
                "title": "AMI"
              },
              {
                "name": "image_config_id",
                "form_options": {
                  "type": "select",
                  "options": {
                    "57c023dd-036c-4684-9e99-a384f54e7f30": "Default"
                  },
                  "flavor_ids": {
                    "57c023dd-036c-4684-9e99-a384f54e7f30": [
                      "t2.nano",
                      "t1.micro",
                      "t2.micro",
                      "t2.small",
                      "t2.medium",
                      "m3.medium",
                      "m3.large",
                      "m3.xlarge",
                      "m3.2xlarge",
                      "m1.small",
                      "m1.medium",
                      "m1.large",
                      "m1.xlarge",
                      "c3.large",
                      "c3.xlarge",
                      "c3.2xlarge",
                      "c3.4xlarge",
                      "c3.8xlarge",
                      "c1.medium",
                      "c1.xlarge",
                      "cc2.8xlarge",
                      "cc1.4xlarge",
                      "g2.2xlarge",
                      "cg1.4xlarge",
                      "r3.large",
                      "r3.xlarge",
                      "r3.2xlarge",
                      "r3.4xlarge",
                      "r3.8xlarge",
                      "m2.xlarge",
                      "m2.2xlarge",
                      "m2.4xlarge",
                      "cr1.8xlarge",
                      "i2.xlarge",
                      "i2.2xlarge",
                      "i2.4xlarge",
                      "i2.8xlarge",
                      "hi1.4xlarge",
                      "hs1.8xlarge"
                    ]
                  },
                  "required": true
                },
                "title": "AMI Configurations"
              },
              {
                "name": "img_archived",
                "form_options": {
                  "type": "hidden",
                  "readonly": "readonly"
                },
                "title": "img_archived"
              },
              {
                "name": "flavor_id",
                "value": "t2.micro",
                "form_options": {
                  "type": "select",
                  "options": [
                    "t2.nano",
                    "t1.micro",
                    "t2.micro",
                    "t2.small",
                    "t2.medium",
                    "m3.medium",
                    "m3.large",
                    "m3.xlarge",
                    "m3.2xlarge",
                    "m1.small",
                    "m1.medium",
                    "m1.large",
                    "m1.xlarge",
                    "c3.large",
                    "c3.xlarge",
                    "c3.2xlarge",
                    "c3.4xlarge",
                    "c3.8xlarge",
                    "c1.medium",
                    "c1.xlarge",
                    "cc2.8xlarge",
                    "cc1.4xlarge",
                    "g2.2xlarge",
                    "cg1.4xlarge",
                    "r3.large",
                    "r3.xlarge",
                    "r3.2xlarge",
                    "r3.4xlarge",
                    "r3.8xlarge",
                    "m2.xlarge",
                    "m2.2xlarge",
                    "m2.4xlarge",
                    "cr1.8xlarge",
                    "i2.xlarge",
                    "i2.2xlarge",
                    "i2.4xlarge",
                    "i2.8xlarge",
                    "hi1.4xlarge",
                    "hs1.8xlarge"
                  ],
                  "required": true
                },
                "title": "Instance Type"
              },
              {
                "name": "source_dest_check",
                "value": true,
                "form_options": {
                  "type": "checkbox",
                  "required": false
                },
                "title": "Source Destination Check"
              },
              {
                "name": "private_ip_address",
                "value": "10.0.1.237",
                "form_options": {
                  "type": "hidden",
                  "required": false
                },
                "title": "Private IP Address"
              },
              {
                "name": "virtualization_type",
                "value": "hvm",
                "form_options": {
                  "type": "hidden",
                  "readonly": "readonly"
                },
                "title": "Virtualization Type"
              },
              {
                "name": "root_device_type",
                "value": "ebs",
                "form_options": {
                  "type": "hidden",
                  "readonly": "readonly"
                },
                "title": "Root Device Type"
              },
              {
                "name": "platform",
                "value": "non-windows",
                "form_options": {
                  "type": "hidden",
                  "readonly": "readonly"
                },
                "title": "Platform"
              },
              {
                "name": "associate_public_ip",
                "form_options": {
                  "type": "checkbox",
                  "required": false
                },
                "title": "Associate Public IP"
              },
              {
                "name": "block_device_mappings",
                "form_options": {
                  "type": "block_device_mappings"
                },
                "title": "Block Device Mappings"
              },
              {
                "name": "ebs_optimized",
                "form_options": {
                  "type": "checkbox",
                  "required": false
                },
                "title": "EBS Optimized"
              },
              {
                "name": "instance_monitoring",
                "form_options": {
                  "type": "checkbox",
                  "required": false
                },
                "title": "Instance Monitoring"
              },
              {
                "name": "key_name",
                "value": "atit-singapore-VPC",
                "form_options": {
                  "type": "select",
                  "options": [],
                  "data": [],
                  "keepfirstblank": true,
                  "firstblanktext": "Proceed without a Key Pair"
                },
                "title": "Select Key Pair"
              },
              {
                "name": "original_block_device_mappings",
                "value": [
                  {
                    "Ebs.VolumeSize": "8",
                    "Ebs.VolumeType": "gp2",
                    "Ebs.Iops": "24",
                    "Ebs.DeleteOnTermination": true,
                    "DevicePath": "/dev/xvda",
                    "Ebs.encrypted": false
                  }
                ],
                "form_options": {
                  "type": "hidden",
                  "readonly": "readonly"
                },
                "title": "Original Block Device Mappings"
              },
              {
                "name": "filer_ids",
                "value": [],
                "form_options": {
                  "type": "hidden",
                  "readonly": "readonly",
                  "options": [],
                  "required": false
                },
                "title": "Filer Ids"
              },
              {
                "name": "instance_initiated_shutdown_behavior",
                "value": "stop",
                "form_options": {
                  "type": "select",
                  "options": [
                    "stop",
                    "terminate"
                  ],
                  "required": true
                },
                "title": "Instance Initiated Shutdown Behavior"
              },
              {
                "name": "iam_role",
                "form_options": {
                  "type": "select",
                  "options": [
                    null
                  ],
                  "keepfirstblank": true,
                  "firstblanktext": "None",
                  "required": false
                },
                "title": "IAM Role"
              }
            ],
            "interfaces": [
              {
                "id": "8dd5b33d-2557-48a9-8d9d-54d6d4394203",
                "name": "atit-AWS-Linux",
                "interface_type": "Protocols::Server",
                "depends": false,
                "connections": []
              },
              {
                "id": "8b4f13cb-279e-4935-86cd-c9e220f88c9a",
                "interface_type": "Protocols::Vpc",
                "depends": true,
                "connections": [
                  {
                    "id": "71eb8488-952a-4c4e-9cae-1049ddcaa6f0",
                    "interface_id": "8b4f13cb-279e-4935-86cd-c9e220f88c9a",
                    "remote_interface_id": "c6cb2561-6369-4bc4-98f4-4850ae2063a1",
                    "internal": false
                  }
                ]
              },
              {
                "id": "5bf63fc7-1266-4329-a34a-67bb1360e944",
                "interface_type": "Protocols::Subnet",
                "depends": true,
                "connections": [
                  {
                    "id": "b3328c5c-0def-470c-998c-5830521b89be",
                    "interface_id": "5bf63fc7-1266-4329-a34a-67bb1360e944",
                    "remote_interface_id": "39f5d424-f32f-42df-bb5f-db8dfb5a55e0",
                    "internal": false
                  }
                ]
              },
              {
                "id": "5262e316-cc94-408d-92fa-7cd8c7435826",
                "interface_type": "Protocols::SecurityGroup",
                "depends": true,
                "connections": [
                  {
                    "id": "47b90d3e-e67e-4f95-8af8-1af5b27d196b",
                    "interface_id": "5262e316-cc94-408d-92fa-7cd8c7435826",
                    "remote_interface_id": "5b972c87-9b50-437e-b83a-429edb2cb043",
                    "internal": false
                  }
                ]
              },
              {
                "id": "024ef624-2b09-4143-9d7f-b2ee42ccfa84",
                "interface_type": "Protocols::Disk",
                "depends": true,
                "connections": [
                  {
                    "id": "4a61337d-9e45-440f-8d1e-638c00efd117",
                    "interface_id": "024ef624-2b09-4143-9d7f-b2ee42ccfa84",
                    "remote_interface_id": "fe55c334-3569-475c-a306-4f1f8b14428a",
                    "internal": false
                  }
                ]
              }
            ],
            "provides": [
              {
                "name": "server",
                "type": "Protocols::Server",
                "properties": [
                  {
                    "name": "port",
                    "form_options": {
                      "type": "integer"
                    },
                    "title": "Port"
                  }
                ]
              }
            ],
            "depends": [
              {
                "name": "network",
                "type": "Protocols::Network",
                "properties": [
                  {
                    "name": "port",
                    "form_options": {
                      "type": "integer"
                    },
                    "title": "Port"
                  }
                ]
              },
              {
                "name": "disk",
                "type": "Protocols::Disk",
                "properties": [
                  {
                    "name": "port",
                    "form_options": {
                      "type": "integer"
                    },
                    "title": "Port"
                  }
                ]
              },
              {
                "name": "new_relic",
                "type": "Protocols::NewRelic",
                "properties": [
                  {
                    "name": "port",
                    "form_options": {
                      "type": "integer"
                    },
                    "title": "Port"
                  }
                ]
              },
              {
                "name": "TCP",
                "type": "Protocols::IP::TCP",
                "properties": [
                  {
                    "name": "port",
                    "form_options": {
                      "type": "integer"
                    },
                    "title": "Port"
                  }
                ]
              }
            ]
          },
          {
            "id": "ed960376-f0ec-4db8-84ab-6c5106a4d785",
            "name": "52.220.170.12",
            "type": "Services::Network::ElasticIP::AWS",
            "additional_properties": {
              "parent_id": null,
              "depends": [],
              "draggable": false,
              "drawable": false,
              "edge": false,
              "generic_type": "Services::Network::ElasticIP",
              "id": "ed960376-f0ec-4db8-84ab-6c5106a4d785",
              "interfaces": [
                {
                  "id": "86757ef1-c3a5-4c5c-b618-718d2998be4f",
                  "service_id": "ed960376-f0ec-4db8-84ab-6c5106a4d785",
                  "name": "52.220.170.12",
                  "interface_type": "Protocols::ElasticIP",
                  "created_at": "2016-11-14T07:38:52.381Z",
                  "updated_at": "2016-11-14T07:38:52.381Z",
                  "depends": false,
                  "connections": []
                }
              ],
              "name": "52.220.170.12",
              "state": "running",
              "primary_key": null,
              "properties": [],
              "provides": [
                {
                  "name": "elastic_ip",
                  "type": "Protocols::ElasticIP",
                  "properties": [
                    {
                      "name": "port",
                      "form_options": {
                        "type": "integer"
                      },
                      "title": "Port"
                    }
                  ]
                }
              ],
              "service_type": "elasticip",
              "type": "Services::Network::ElasticIP::AWS",
              "vpc_id": "b4d269a4-49c5-42bf-b7c1-2818118fe6a7"
            },
            "properties": [],
            "interfaces": [
              {
                "id": "86757ef1-c3a5-4c5c-b618-718d2998be4f",
                "name": "52.220.170.12",
                "interface_type": "Protocols::ElasticIP",
                "depends": false,
                "connections": []
              }
            ],
            "provides": [
              {
                "name": "elastic_ip",
                "type": "Protocols::ElasticIP",
                "properties": [
                  {
                    "name": "port",
                    "form_options": {
                      "type": "integer"
                    },
                    "title": "Port"
                  }
                ]
              }
            ],
            "depends": []
          },
          {
            "id": "b2b9d573-2b2a-426c-9bf4-74e05c862f6f",
            "name": "vol-afbade2b",
            "type": "Services::Compute::Server::Volume::AWS",
            "internal": true,
            "additional_properties": {
              "parent_id": "33371a94-acd7-4aeb-a235-d9378e38733f",
              "depends": [],
              "draggable": true,
              "drawable": true,
              "edge": false,
              "generic_type": "Services::Compute::Server::Volume",
              "id": "b2b9d573-2b2a-426c-9bf4-74e05c862f6f",
              "interfaces": [
                {
                  "id": "9d72a453-f282-4a5c-a171-223725c77d8c",
                  "service_id": "b2b9d573-2b2a-426c-9bf4-74e05c862f6f",
                  "name": "vol-afbade2b",
                  "interface_type": "Protocols::Disk",
                  "created_at": "2016-11-14T07:38:52.403Z",
                  "updated_at": "2016-11-14T07:38:52.403Z",
                  "depends": false,
                  "connections": []
                },
                {
                  "id": "edf650ad-97a9-48db-a13c-cf66d49aed94",
                  "service_id": "b2b9d573-2b2a-426c-9bf4-74e05c862f6f",
                  "name": null,
                  "interface_type": "Protocols::Vpc",
                  "created_at": "2016-11-14T07:38:59.489Z",
                  "updated_at": "2016-11-14T07:38:59.489Z",
                  "depends": true,
                  "connections": [
                    {
                      "id": "3ef3b938-b97d-4927-8203-dd1a7c06a798",
                      "interface_id": "edf650ad-97a9-48db-a13c-cf66d49aed94",
                      "remote_interface_id": "c6cb2561-6369-4bc4-98f4-4850ae2063a1",
                      "created_at": "2016-11-14T07:38:59.493Z",
                      "updated_at": "2016-11-14T07:38:59.493Z",
                      "internal": false
                    }
                  ]
                },
                {
                  "id": "7e512ae4-ec94-4903-ac1f-8ebad5c33e16",
                  "service_id": "b2b9d573-2b2a-426c-9bf4-74e05c862f6f",
                  "name": null,
                  "interface_type": "Protocols::AvailabilityZone",
                  "created_at": "2016-11-14T07:38:59.534Z",
                  "updated_at": "2016-11-14T07:38:59.534Z",
                  "depends": true,
                  "connections": [
                    {
                      "id": "d4f2db48-37cc-4187-adfc-7f1b2d3235d1",
                      "interface_id": "7e512ae4-ec94-4903-ac1f-8ebad5c33e16",
                      "remote_interface_id": "65ff2a0f-0fd7-45a7-b34a-56dcfb1d7f67",
                      "created_at": "2016-11-14T07:38:59.537Z",
                      "updated_at": "2016-11-14T07:38:59.537Z",
                      "internal": false
                    }
                  ]
                }
              ],
              "name": "vol-afbade2b",
              "state": "running",
              "primary_key": null,
              "properties": [
                {
                  "name": "size",
                  "value": "8",
                  "form_options": {
                    "type": "range",
                    "min": "4",
                    "max": "1024",
                    "step": "1"
                  },
                  "title": "Size"
                },
                {
                  "name": "volume_type",
                  "value": "gp2",
                  "form_options": {
                    "type": "select",
                    "options": [
                      "General Purpose (SSD)",
                      "Provisioned IOPS (SSD)",
                      "Magnetic"
                    ]
                  },
                  "title": "Type"
                },
                {
                  "name": "iops",
                  "value": "100",
                  "form_options": {
                    "type": "text",
                    "depends_on": false
                  },
                  "title": "IOPS"
                },
                {
                  "name": "device",
                  "value": "/dev/xvda",
                  "form_options": {
                    "type": "text"
                  },
                  "title": "Device Path"
                },
                {
                  "name": "delete_on_termination",
                  "value": true,
                  "form_options": {
                    "type": "checkbox"
                  },
                  "title": "Delete On Termination"
                },
                {
                  "name": "root_device",
                  "value": true,
                  "form_options": {
                    "type": "hidden",
                    "readonly": "readonly"
                  },
                  "title": "Root Device"
                },
                {
                  "name": "configured_with_ami",
                  "form_options": {
                    "type": "hidden",
                    "readonly": "readonly"
                  },
                  "title": "Configured With AMI"
                },
                {
                  "name": "create_from_snapshot",
                  "form_options": {
                    "environment_with_snapshots_exists": false,
                    "unallocated_snapshots_exists": false,
                    "type": "checkbox"
                  },
                  "title": "Create From Snapshot"
                },
                {
                  "name": "snapshot_id",
                  "form_options": {
                    "type": "select",
                    "options": []
                  },
                  "title": "Snapshot ID"
                },
                {
                  "name": "snapshot_provider_id",
                  "value": "snap-0dbb3696",
                  "form_options": {
                    "type": "hidden",
                    "readonly": "readonly"
                  },
                  "title": "Snapshot Provider ID"
                },
                {
                  "name": "snapshot_environment_id",
                  "form_options": {
                    "type": "select",
                    "options": []
                  },
                  "title": "Snapshot Environment ID"
                }
              ],
              "provides": [
                {
                  "name": "disk",
                  "type": "Protocols::Disk",
                  "properties": [
                    {
                      "name": "port",
                      "form_options": {
                        "type": "integer"
                      },
                      "title": "Port"
                    }
                  ]
                }
              ],
              "service_type": "volume",
              "type": "Services::Compute::Server::Volume::AWS",
              "vpc_id": "b4d269a4-49c5-42bf-b7c1-2818118fe6a7",
              "internal": true,
              "numbered": true
            },
            "tags": {},
            "properties": [
              {
                "name": "size",
                "value": "8",
                "form_options": {
                  "type": "range",
                  "min": "4",
                  "max": "1024",
                  "step": "1"
                },
                "title": "Size"
              },
              {
                "name": "volume_type",
                "value": "gp2",
                "form_options": {
                  "type": "select",
                  "options": [
                    "General Purpose (SSD)",
                    "Provisioned IOPS (SSD)",
                    "Magnetic"
                  ]
                },
                "title": "Type"
              },
              {
                "name": "iops",
                "value": "100",
                "form_options": {
                  "type": "text",
                  "depends_on": false
                },
                "title": "IOPS"
              },
              {
                "name": "device",
                "value": "/dev/xvda",
                "form_options": {
                  "type": "text"
                },
                "title": "Device Path"
              },
              {
                "name": "delete_on_termination",
                "value": true,
                "form_options": {
                  "type": "checkbox"
                },
                "title": "Delete On Termination"
              },
              {
                "name": "root_device",
                "value": true,
                "form_options": {
                  "type": "hidden",
                  "readonly": "readonly"
                },
                "title": "Root Device"
              },
              {
                "name": "configured_with_ami",
                "form_options": {
                  "type": "hidden",
                  "readonly": "readonly"
                },
                "title": "Configured With AMI"
              },
              {
                "name": "create_from_snapshot",
                "form_options": {
                  "environment_with_snapshots_exists": false,
                  "unallocated_snapshots_exists": false,
                  "type": "checkbox"
                },
                "title": "Create From Snapshot"
              },
              {
                "name": "snapshot_id",
                "form_options": {
                  "type": "select",
                  "options": []
                },
                "title": "Snapshot ID"
              },
              {
                "name": "snapshot_provider_id",
                "value": "snap-0dbb3696",
                "form_options": {
                  "type": "hidden",
                  "readonly": "readonly"
                },
                "title": "Snapshot Provider ID"
              },
              {
                "name": "snapshot_environment_id",
                "form_options": {
                  "type": "select",
                  "options": []
                },
                "title": "Snapshot Environment ID"
              }
            ],
            "interfaces": [
              {
                "id": "9d72a453-f282-4a5c-a171-223725c77d8c",
                "name": "vol-afbade2b",
                "interface_type": "Protocols::Disk",
                "depends": false,
                "connections": []
              },
              {
                "id": "edf650ad-97a9-48db-a13c-cf66d49aed94",
                "interface_type": "Protocols::Vpc",
                "depends": true,
                "connections": [
                  {
                    "id": "3ef3b938-b97d-4927-8203-dd1a7c06a798",
                    "interface_id": "edf650ad-97a9-48db-a13c-cf66d49aed94",
                    "remote_interface_id": "c6cb2561-6369-4bc4-98f4-4850ae2063a1",
                    "internal": false
                  }
                ]
              },
              {
                "id": "7e512ae4-ec94-4903-ac1f-8ebad5c33e16",
                "interface_type": "Protocols::AvailabilityZone",
                "depends": true,
                "connections": [
                  {
                    "id": "d4f2db48-37cc-4187-adfc-7f1b2d3235d1",
                    "interface_id": "7e512ae4-ec94-4903-ac1f-8ebad5c33e16",
                    "remote_interface_id": "65ff2a0f-0fd7-45a7-b34a-56dcfb1d7f67",
                    "internal": false
                  }
                ]
              }
            ],
            "provides": [
              {
                "name": "disk",
                "type": "Protocols::Disk",
                "properties": [
                  {
                    "name": "port",
                    "form_options": {
                      "type": "integer"
                    },
                    "title": "Port"
                  }
                ]
              }
            ],
            "depends": []
          },
          {
            "id": "fcd56f05-7df9-40ea-bde6-4b4285076d93",
            "name": "vol-e714693a",
            "type": "Services::Compute::Server::Volume::AWS",
            "internal": true,
            "additional_properties": {
              "parent_id": "c7a915a6-8aac-46f2-898c-16f05b06e9f1",
              "depends": [],
              "draggable": true,
              "drawable": true,
              "edge": false,
              "generic_type": "Services::Compute::Server::Volume",
              "id": "fcd56f05-7df9-40ea-bde6-4b4285076d93",
              "interfaces": [
                {
                  "id": "fe55c334-3569-475c-a306-4f1f8b14428a",
                  "service_id": "fcd56f05-7df9-40ea-bde6-4b4285076d93",
                  "name": "vol-e714693a",
                  "interface_type": "Protocols::Disk",
                  "created_at": "2016-11-14T07:38:52.426Z",
                  "updated_at": "2016-11-14T07:38:52.426Z",
                  "depends": false,
                  "connections": []
                },
                {
                  "id": "aaa72fb2-59f9-4f83-9874-9098deb0499a",
                  "service_id": "fcd56f05-7df9-40ea-bde6-4b4285076d93",
                  "name": null,
                  "interface_type": "Protocols::Vpc",
                  "created_at": "2016-11-14T07:38:59.930Z",
                  "updated_at": "2016-11-14T07:38:59.930Z",
                  "depends": true,
                  "connections": [
                    {
                      "id": "17a4a07b-8cf7-4285-a6dd-706267f39ef7",
                      "interface_id": "aaa72fb2-59f9-4f83-9874-9098deb0499a",
                      "remote_interface_id": "c6cb2561-6369-4bc4-98f4-4850ae2063a1",
                      "created_at": "2016-11-14T07:38:59.933Z",
                      "updated_at": "2016-11-14T07:38:59.933Z",
                      "internal": false
                    }
                  ]
                },
                {
                  "id": "cb957aca-4efc-4ad4-90dd-e7c9992df3fa",
                  "service_id": "fcd56f05-7df9-40ea-bde6-4b4285076d93",
                  "name": null,
                  "interface_type": "Protocols::AvailabilityZone",
                  "created_at": "2016-11-14T07:38:59.974Z",
                  "updated_at": "2016-11-14T07:38:59.974Z",
                  "depends": true,
                  "connections": [
                    {
                      "id": "afd31fd6-05c9-42a5-8658-edbd3d69d04a",
                      "interface_id": "cb957aca-4efc-4ad4-90dd-e7c9992df3fa",
                      "remote_interface_id": "d7aabf56-bf06-4f95-8ee9-508311898e1e",
                      "created_at": "2016-11-14T07:38:59.978Z",
                      "updated_at": "2016-11-14T07:38:59.978Z",
                      "internal": false
                    }
                  ]
                }
              ],
              "name": "vol-e714693a",
              "state": "running",
              "primary_key": null,
              "properties": [
                {
                  "name": "size",
                  "value": "8",
                  "form_options": {
                    "type": "range",
                    "min": "4",
                    "max": "1024",
                    "step": "1"
                  },
                  "title": "Size"            
                },
                {
                  "name": "volume_type",
                  "value": "gp2",
                  "form_options": {
                    "type": "select",
                    "options": [
                      "General Purpose (SSD)",
                      "Provisioned IOPS (SSD)",
                      "Magnetic"
                    ]
                  },
                  "title": "Type"
                },
                {
                  "name": "iops",
                  "value": "100",
                  "form_options": {
                    "type": "text",
                    "depends_on": false
                  },
                  "title": "IOPS"            
                },
                {
                  "name": "device",
                  "value": "/dev/xvda",
                  "form_options": {
                    "type": "text"
                  },
                  "title": "Device Path"
                },
                {
                  "name": "delete_on_termination",
                  "value": true,
                  "form_options": {
                    "type": "checkbox"
                  },
                  "title": "Delete On Termination"
                },
                {
                  "name": "root_device",
                  "value": true,
                  "form_options": {
                    "type": "hidden",
                    "readonly": "readonly"
                  },
                  "title": "Root Device"
                },
                {
                  "name": "configured_with_ami",
                  "form_options": {
                    "type": "hidden",
                    "readonly": "readonly"
                  },
                  "title": "Configured With AMI"
                },
                {
                  "name": "create_from_snapshot",
                  "form_options": {
                    "environment_with_snapshots_exists": false,
                    "unallocated_snapshots_exists": false,
                    "type": "checkbox"
                  },
                  "title": "Create From Snapshot"
                },
                {
                  "name": "snapshot_id",
                  "form_options": {
                    "type": "select",
                    "options": []
                  },
                  "title": "Snapshot ID"
                },
                {
                  "name": "snapshot_provider_id",
                  "value": "snap-0dbb3696",
                  "form_options": {
                    "type": "hidden",
                    "readonly": "readonly"
                  },
                  "title": "Snapshot Provider ID"
                },
                {
                  "name": "snapshot_environment_id",
                  "form_options": {
                    "type": "select",
                    "options": []
                  },
                  "title": "Snapshot Environment ID"
                }
              ],
              "provides": [
                {
                  "name": "disk",
                  "type": "Protocols::Disk",
                  "properties": [
                    {
                      "name": "port",
                      "form_options": {
                        "type": "integer"
                      },
                      "title": "Port"
                    }
                  ]
                }
              ],
              "service_type": "volume",
              "type": "Services::Compute::Server::Volume::AWS",
              "vpc_id": "b4d269a4-49c5-42bf-b7c1-2818118fe6a7",
              "internal": true,
              "numbered": true
            },
            "tags": {},
            "properties": [
              {
                "name": "size",
                "value": "8",
                "form_options": {
                  "type": "range",
                  "min": "4",
                  "max": "1024",
                  "step": "1"
                },
                "title": "Size"
              },
              {
                "name": "volume_type",
                "value": "gp2",
                "form_options": {
                  "type": "select",
                  "options": [
                    "General Purpose (SSD)",
                    "Provisioned IOPS (SSD)",
                    "Magnetic"
                  ]
                },
                "title": "Type"
              },
              {
                "name": "iops",
                "value": "100",
                "form_options": {
                  "type": "text",
                  "depends_on": false
                },
                "title": "IOPS"
              },
              {
                "name": "device",
                "value": "/dev/xvda",
                "form_options": {
                  "type": "text"
                },
                "title": "Device Path"
              },
              {
                "name": "delete_on_termination",
                "value": true,
                "form_options": {
                  "type": "checkbox"
                },
                "title": "Delete On Termination"
              },
              {
                "name": "root_device",
                "value": true,
                "form_options": {
                  "type": "hidden",
                  "readonly": "readonly"
                },
                "title": "Root Device"
              },
              {
                "name": "configured_with_ami",
                "form_options": {
                  "type": "hidden",
                  "readonly": "readonly"
                },
                "title": "Configured With AMI"
              },
              {
                "name": "create_from_snapshot",
                "form_options": {
                  "environment_with_snapshots_exists": false,
                  "unallocated_snapshots_exists": false,
                  "type": "checkbox"
                },
                "title": "Create From Snapshot"
              },
              {
                "name": "snapshot_id",
                "form_options": {
                  "type": "select",
                  "options": []
                },
                "title": "Snapshot ID"
              },
              {
                "name": "snapshot_provider_id",
                "value": "snap-0dbb3696",
                "form_options": {
                  "type": "hidden",
                  "readonly": "readonly"
                },
                "title": "Snapshot Provider ID"
              },
              {
                "name": "snapshot_environment_id",
                "form_options": {
                  "type": "select",
                  "options": []
                },
                "title": "Snapshot Environment ID"
              }
            ],
            "interfaces": [
              {
                "id": "fe55c334-3569-475c-a306-4f1f8b14428a",
                "name": "vol-e714693a",
                "interface_type": "Protocols::Disk",
                "depends": false,
                "connections": []
              },
              {
                "id": "aaa72fb2-59f9-4f83-9874-9098deb0499a",
                "interface_type": "Protocols::Vpc",
                "depends": true,
                "connections": [
                  {
                    "id": "17a4a07b-8cf7-4285-a6dd-706267f39ef7",
                    "interface_id": "aaa72fb2-59f9-4f83-9874-9098deb0499a",
                    "remote_interface_id": "c6cb2561-6369-4bc4-98f4-4850ae2063a1",
                    "internal": false
                  }
                ]
              },
              {
                "id": "cb957aca-4efc-4ad4-90dd-e7c9992df3fa",
                "interface_type": "Protocols::AvailabilityZone",
                "depends": true,
                "connections": [
                  {
                    "id": "afd31fd6-05c9-42a5-8658-edbd3d69d04a",
                    "interface_id": "cb957aca-4efc-4ad4-90dd-e7c9992df3fa",
                    "remote_interface_id": "d7aabf56-bf06-4f95-8ee9-508311898e1e",
                    "internal": false
                  }
                ]
              }
            ],
            "provides": [
              {
                "name": "disk",
                "type": "Protocols::Disk",
                "properties": [
                  {
                    "name": "port",
                    "form_options": {
                      "type": "integer"
                    },
                    "title": "Port"
                  }
                ]
              }
            ],
            "depends": []
          },
          {
            "id": "738d8512-54eb-4dd2-81bd-de63007eec55",
            "name": "eni-e485849d",
            "type": "Services::Network::NetworkInterface::AWS",
            "internal": true,
            "expose": true,
            "additional_properties": {
              "parent_id": "411b10b4-bb15-4650-bf67-2bf10c414580",
              "depends": [
                {
                  "name": "subnet",
                  "protocol": {
                    "generated_attribute_methods": {
                      "_mutex": {}
                    },
                    "attribute_methods_generated": false,
                    "relation_delegate_cache": {
                      "ActiveRecord::Relation": {
                        "delegation_mutex": {}
                      },
                      "ActiveRecord::Associations::CollectionProxy": {
                        "delegation_mutex": {}
                      },
                      "ActiveRecord::AssociationRelation": {
                        "delegation_mutex": {}
                      }
                    },
                    "_store_accessors_module": {}
                  }
                },
                {
                  "name": "internet",
                  "protocol": {
                    "generated_attribute_methods": {
                      "_mutex": {}
                    },
                    "attribute_methods_generated": false,
                    "relation_delegate_cache": {
                      "ActiveRecord::Relation": {
                        "delegation_mutex": {}
                      },
                      "ActiveRecord::Associations::CollectionProxy": {
                        "delegation_mutex": {}
                      },
                      "ActiveRecord::AssociationRelation": {
                        "delegation_mutex": {}
                      }
                    }
                  }
                }
              ],
              "draggable": null,
              "drawable": null,
              "edge": false,
              "generic_type": "Services::Network::NetworkInterface",
              "id": "738d8512-54eb-4dd2-81bd-de63007eec55",
              "interfaces": [
                {
                  "id": "e66c30c8-f48e-49ee-89bc-d6bb9b833ce1",
                  "service_id": "738d8512-54eb-4dd2-81bd-de63007eec55",
                  "name": "eni-e485849d",
                  "interface_type": "Protocols::NetworkInterface",
                  "created_at": "2016-11-14T07:38:52.470Z",
                  "updated_at": "2016-11-14T07:38:52.470Z",
                  "depends": false,
                  "connections": []
                },
                {
                  "id": "7bd9fc06-d82d-41eb-8d39-6cf6f035e045",
                  "service_id": "738d8512-54eb-4dd2-81bd-de63007eec55",
                  "name": null,
                  "interface_type": "Protocols::Vpc",
                  "created_at": "2016-11-14T07:39:00.451Z",
                  "updated_at": "2016-11-14T07:39:00.451Z",
                  "depends": true,
                  "connections": [
                    {
                      "id": "0ac29b16-a9e3-44e9-9e45-f85157d89127",
                      "interface_id": "7bd9fc06-d82d-41eb-8d39-6cf6f035e045",
                      "remote_interface_id": "c6cb2561-6369-4bc4-98f4-4850ae2063a1",
                      "created_at": "2016-11-14T07:39:00.455Z",
                      "updated_at": "2016-11-14T07:39:00.455Z",
                      "internal": false
                    }
                  ]
                },
                {
                  "id": "08c524b3-c8ef-49d7-8f48-15e1f68d6d1c",
                  "service_id": "738d8512-54eb-4dd2-81bd-de63007eec55",
                  "name": null,
                  "interface_type": "Protocols::Subnet",
                  "created_at": "2016-11-14T07:39:00.499Z",
                  "updated_at": "2016-11-14T07:39:00.499Z",
                  "depends": true,
                  "connections": [
                    {
                      "id": "5fed3267-5eea-4441-b65a-f7594e04ff5c",
                      "interface_id": "08c524b3-c8ef-49d7-8f48-15e1f68d6d1c",
                      "remote_interface_id": "39f5d424-f32f-42df-bb5f-db8dfb5a55e0",
                      "created_at": "2016-11-14T07:39:00.502Z",
                      "updated_at": "2016-11-14T07:39:00.502Z",
                      "internal": false
                    }
                  ]
                }
              ],
              "name": "eni-e485849d",
              "state": "running",
              "primary_key": null,
              "properties": [
                {
                  "name": "private_ips",
                  "value": [
                    {
                      "privateIpAddress": "10.0.1.167",
                      "primary": "true",
                      "item": "eipassoc-0e455f6a"
                    }
                  ],
                  "form_options": {
                    "type": "private_ips_type",
                    "options": [],
                    "required": true
                  },
                  "title": "Private Ips"
                },
                {
                  "name": "primary",
                  "value": false,
                  "form_options": {
                    "type": "hidden",
                    "required": false,
                    "readonly": "readonly"
                  },
                  "title": "Primary"
                },
                {
                  "name": "description",
                  "value": "Interface for NAT Gateway nat-040707f812add7d57",
                  "form_options": {
                    "type": "text",
                    "required": true
                  },
                  "title": "Description"
                },
                {
                  "name": "mac_address",
                  "value": "02:d1:fc:98:20:dd",
                  "form_options": {
                    "type": "hidden",
                    "readonly": "readonly"
                  },
                  "title": "Mac Address"
                },
                {
                  "name": "source_dest_check",
                  "value": false,
                  "form_options": {
                    "type": "checkbox",
                    "required": false
                  },
                  "title": "Source Destination Check"
                }
              ],
              "provides": [
                {
                  "name": "network_interface",
                  "type": "Protocols::NetworkInterface",
                  "properties": [
                    {
                      "name": "port",
                      "form_options": {
                        "type": "integer"
                      },
                      "title": "Port"
                    }
                  ]
                }
              ],
              "service_type": "networkinterface",
              "type": "Services::Network::NetworkInterface::AWS",
              "vpc_id": "b4d269a4-49c5-42bf-b7c1-2818118fe6a7"
            },
            "tags": {},
            "properties": [
              {
                "name": "private_ips",
                "value": [
                  {
                    "privateIpAddress": "10.0.1.167",
                    "primary": "true",
                    "item": "eipassoc-0e455f6a"
                  }
                ],
                "form_options": {
                  "type": "private_ips_type",
                  "options": [],
                  "required": true
                },
                "title": "Private Ips"
              },
              {
                "name": "primary",
                "value": false,
                "form_options": {
                  "type": "hidden",
                  "required": false,
                  "readonly": "readonly"
                },
                "title": "Primary"
              },
              {
                "name": "description",
                "value": "Interface for NAT Gateway nat-040707f812add7d57",
                "form_options": {
                  "type": "text",
                  "required": true
                },
                "title": "Description"
              },
              {
                "name": "mac_address",
                "value": "02:d1:fc:98:20:dd",
                "form_options": {
                  "type": "hidden",
                  "readonly": "readonly"
                },
                "title": "Mac Address"
              },
              {
                "name": "source_dest_check",
                "value": false,
                "form_options": {
                  "type": "checkbox",
                  "required": false
                },
                "title": "Source Destination Check"
              }
            ],
            "interfaces": [
              {
                "id": "e66c30c8-f48e-49ee-89bc-d6bb9b833ce1",
                "name": "eni-e485849d",
                "interface_type": "Protocols::NetworkInterface",
                "depends": false,
                "connections": []
              },
              {
                "id": "7bd9fc06-d82d-41eb-8d39-6cf6f035e045",
                "interface_type": "Protocols::Vpc",
                "depends": true,
                "connections": [
                  {
                    "id": "0ac29b16-a9e3-44e9-9e45-f85157d89127",
                    "interface_id": "7bd9fc06-d82d-41eb-8d39-6cf6f035e045",
                    "remote_interface_id": "c6cb2561-6369-4bc4-98f4-4850ae2063a1",
                    "internal": false
                  }
                ]
              },
              {
                "id": "08c524b3-c8ef-49d7-8f48-15e1f68d6d1c",
                "interface_type": "Protocols::Subnet",
                "depends": true,
                "connections": [
                  {
                    "id": "5fed3267-5eea-4441-b65a-f7594e04ff5c",
                    "interface_id": "08c524b3-c8ef-49d7-8f48-15e1f68d6d1c",
                    "remote_interface_id": "39f5d424-f32f-42df-bb5f-db8dfb5a55e0",
                    "internal": false
                  }
                ]
              }
            ],
            "provides": [
              {
                "name": "network_interface",
                "type": "Protocols::NetworkInterface",
                "properties": [
                  {
                    "name": "port",
                    "form_options": {
                      "type": "integer"
                    },
                    "title": "Port"
                  }
                ]
              }
            ],
            "depends": [
              {
                "name": "subnet",
                "type": "Protocols::Subnet",
                "properties": [
                  {
                    "name": "port",
                    "form_options": {
                      "type": "integer"
                    },
                    "title": "Port"
                  }
                ]
              },
              {
                "name": "internet",
                "type": "Protocols::IP",
                "properties": [
                  {
                    "name": "port",
                    "form_options": {
                      "type": "integer"
                    },
                    "title": "Port"
                  }
                ]
              }
            ]
          },
          {
            "id": "9c611131-1d40-42ec-9ea0-bc140664f589",
            "name": "eni-5d232224",
            "type": "Services::Network::NetworkInterface::AWS",
            "internal": true,
            "expose": true,
            "additional_properties": {
              "parent_id": "411b10b4-bb15-4650-bf67-2bf10c414580",
              "depends": [
                {
                  "name": "subnet",
                  "protocol": {
                    "generated_attribute_methods": {
                      "_mutex": {}
                    },
                    "attribute_methods_generated": false,
                    "relation_delegate_cache": {
                      "ActiveRecord::Relation": {
                        "delegation_mutex": {}
                      },
                      "ActiveRecord::Associations::CollectionProxy": {
                        "delegation_mutex": {}
                      },
                      "ActiveRecord::AssociationRelation": {
                        "delegation_mutex": {}
                      }
                    },
                    "_store_accessors_module": {}
                  }
                },
                {
                  "name": "internet",
                  "protocol": {
                    "generated_attribute_methods": {
                      "_mutex": {}
                    },
                    "attribute_methods_generated": false,
                    "relation_delegate_cache": {
                      "ActiveRecord::Relation": {
                        "delegation_mutex": {}
                      },
                      "ActiveRecord::Associations::CollectionProxy": {
                        "delegation_mutex": {}
                      },
                      "ActiveRecord::AssociationRelation": {
                        "delegation_mutex": {}
                      }
                    }
                  }
                }
              ],
              "draggable": null,
              "drawable": null,
              "edge": false,
              "generic_type": "Services::Network::NetworkInterface",
              "id": "9c611131-1d40-42ec-9ea0-bc140664f589",
              "interfaces": [
                {
                  "id": "ef3f4044-460d-4e7c-9ca9-97240cf0e4ce",
                  "service_id": "9c611131-1d40-42ec-9ea0-bc140664f589",
                  "name": "eni-5d232224",
                  "interface_type": "Protocols::NetworkInterface",
                  "created_at": "2016-11-14T07:38:52.491Z",
                  "updated_at": "2016-11-14T07:38:52.491Z",
                  "depends": false,
                  "connections": []
                },
                {
                  "id": "e0ec1bf3-7984-456a-a126-b9e3ec7043da",
                  "service_id": "9c611131-1d40-42ec-9ea0-bc140664f589",
                  "name": null,
                  "interface_type": "Protocols::Vpc",
                  "created_at": "2016-11-14T07:39:00.651Z",
                  "updated_at": "2016-11-14T07:39:00.651Z",
                  "depends": true,
                  "connections": [
                    {
                      "id": "d835973b-45b8-4813-ae66-0a5043de517d",
                      "interface_id": "e0ec1bf3-7984-456a-a126-b9e3ec7043da",
                      "remote_interface_id": "c6cb2561-6369-4bc4-98f4-4850ae2063a1",
                      "created_at": "2016-11-14T07:39:00.654Z",
                      "updated_at": "2016-11-14T07:39:00.654Z",
                      "internal": false
                    }
                  ]
                },
                {
                  "id": "0f8c9e78-530b-49c7-9c99-23b92d5f5686",
                  "service_id": "9c611131-1d40-42ec-9ea0-bc140664f589",
                  "name": null,
                  "interface_type": "Protocols::Subnet",
                  "created_at": "2016-11-14T07:39:00.698Z",
                  "updated_at": "2016-11-14T07:39:00.698Z",
                  "depends": true,
                  "connections": [
                    {
                      "id": "d4cfce91-7a68-4d4a-87a8-389edacb7f90",
                      "interface_id": "0f8c9e78-530b-49c7-9c99-23b92d5f5686",
                      "remote_interface_id": "39f5d424-f32f-42df-bb5f-db8dfb5a55e0",
                      "created_at": "2016-11-14T07:39:00.701Z",
                      "updated_at": "2016-11-14T07:39:00.701Z",
                      "internal": false
                    }
                  ]
                },
                {
                  "id": "055d3a76-175f-450c-b357-7ff8175a2841",
                  "service_id": "9c611131-1d40-42ec-9ea0-bc140664f589",
                  "name": null,
                  "interface_type": "Protocols::SecurityGroup",
                  "created_at": "2016-11-14T07:39:00.745Z",
                  "updated_at": "2016-11-14T07:39:00.745Z",
                  "depends": true,
                  "connections": [
                    {
                      "id": "2e986b4a-90a8-4c55-ab8e-8e50fce9f9cd",
                      "interface_id": "055d3a76-175f-450c-b357-7ff8175a2841",
                      "remote_interface_id": "5b972c87-9b50-437e-b83a-429edb2cb043",
                      "created_at": "2016-11-14T07:39:00.748Z",
                      "updated_at": "2016-11-14T07:39:00.748Z",
                      "internal": false
                    }
                  ]
                },
                {
                  "id": "0e3e2485-5fe0-4d6b-b410-84895669d1e2",
                  "service_id": "9c611131-1d40-42ec-9ea0-bc140664f589",
                  "name": null,
                  "interface_type": "Protocols::Server",
                  "created_at": "2016-11-14T07:39:00.792Z",
                  "updated_at": "2016-11-14T07:39:00.792Z",
                  "depends": true,
                  "connections": [
                    {
                      "id": "6c222a87-2540-4c8d-824c-b69e2e318aa4",
                      "interface_id": "0e3e2485-5fe0-4d6b-b410-84895669d1e2",
                      "remote_interface_id": "8dd5b33d-2557-48a9-8d9d-54d6d4394203",
                      "created_at": "2016-11-14T07:39:00.795Z",
                      "updated_at": "2016-11-14T07:39:00.795Z",
                      "internal": false
                    }
                  ]
                }
              ],
              "name": "eni-5d232224",
              "state": "running",
              "primary_key": null,
              "properties": [
                {
                  "name": "private_ips",
                  "value": [
                    {
                      "privateIpAddress": "10.0.1.237",
                      "primary": "true",
                      "item": "amazon"
                    }
                  ],
                  "form_options": {
                    "type": "private_ips_type",
                    "options": [],
                    "required": true
                  },
                  "title": "Private Ips"
                },
                {
                  "name": "primary",
                  "value": true,
                  "form_options": {
                    "type": "hidden",
                    "required": false,
                    "readonly": "readonly"
                  },
                  "title": "Primary"
                },
                {
                  "name": "description",
                  "value": "Primary network interface",
                  "form_options": {
                    "type": "text",
                    "required": true
                  },
                  "title": "Description"
                },
                {
                  "name": "mac_address",
                  "value": "02:b3:b3:3c:16:61",
                  "form_options": {
                    "type": "hidden",
                    "readonly": "readonly"
                  },
                  "title": "Mac Address"
                },
                {
                  "name": "source_dest_check",
                  "value": true,
                  "form_options": {
                    "type": "checkbox",
                    "required": false
                  },
                  "title": "Source Destination Check"
                }
              ],
              "provides": [
                {
                  "name": "network_interface",
                  "type": "Protocols::NetworkInterface",
                  "properties": [
                    {
                      "name": "port",
                      "form_options": {
                        "type": "integer"
                      },
                      "title": "Port"
                    }
                  ]
                }
              ],
              "service_type": "networkinterface",
              "type": "Services::Network::NetworkInterface::AWS",
              "vpc_id": "b4d269a4-49c5-42bf-b7c1-2818118fe6a7"
            },
            "tags": {},
            "properties": [
              {
                "name": "private_ips",
                "value": [
                  {
                    "privateIpAddress": "10.0.1.237",
                    "primary": "true",
                    "item": "amazon"
                  }
                ],
                "form_options": {
                  "type": "private_ips_type",
                  "options": [],
                  "required": true
                },
                "title": "Private Ips"
              },
              {
                "name": "primary",
                "value": true,
                "form_options": {
                  "type": "hidden",
                  "required": false,
                  "readonly": "readonly"
                },
                "title": "Primary"
              },
              {
                "name": "description",
                "value": "Primary network interface",
                "form_options": {
                  "type": "text",
                  "required": true
                },
                "title": "Description"
              },
              {
                "name": "mac_address",
                "value": "02:b3:b3:3c:16:61",
                "form_options": {
                  "type": "hidden",
                  "readonly": "readonly"
                },
                "title": "Mac Address"
              },
              {
                "name": "source_dest_check",
                "value": true,
                "form_options": {
                  "type": "checkbox",
                  "required": false
                },
                "title": "Source Destination Check"
              }
            ],
            "interfaces": [
              {
                "id": "ef3f4044-460d-4e7c-9ca9-97240cf0e4ce",
                "name": "eni-5d232224",
                "interface_type": "Protocols::NetworkInterface",
                "depends": false,
                "connections": []
              },
              {
                "id": "e0ec1bf3-7984-456a-a126-b9e3ec7043da",
                "interface_type": "Protocols::Vpc",
                "depends": true,
                "connections": [
                  {
                    "id": "d835973b-45b8-4813-ae66-0a5043de517d",
                    "interface_id": "e0ec1bf3-7984-456a-a126-b9e3ec7043da",
                    "remote_interface_id": "c6cb2561-6369-4bc4-98f4-4850ae2063a1",
                    "internal": false
                  }
                ]
              },
              {
                "id": "0f8c9e78-530b-49c7-9c99-23b92d5f5686",
                "interface_type": "Protocols::Subnet",
                "depends": true,
                "connections": [
                  {
                    "id": "d4cfce91-7a68-4d4a-87a8-389edacb7f90",
                    "interface_id": "0f8c9e78-530b-49c7-9c99-23b92d5f5686",
                    "remote_interface_id": "39f5d424-f32f-42df-bb5f-db8dfb5a55e0",
                    "internal": false
                  }
                ]
              },
              {
                "id": "055d3a76-175f-450c-b357-7ff8175a2841",
                "interface_type": "Protocols::SecurityGroup",
                "depends": true,
                "connections": [
                  {
                    "id": "2e986b4a-90a8-4c55-ab8e-8e50fce9f9cd",
                    "interface_id": "055d3a76-175f-450c-b357-7ff8175a2841",
                    "remote_interface_id": "5b972c87-9b50-437e-b83a-429edb2cb043",
                    "internal": false
                  }
                ]
              },
              {
                "id": "0e3e2485-5fe0-4d6b-b410-84895669d1e2",
                "interface_type": "Protocols::Server",
                "depends": true,
                "connections": [
                  {
                    "id": "6c222a87-2540-4c8d-824c-b69e2e318aa4",
                    "interface_id": "0e3e2485-5fe0-4d6b-b410-84895669d1e2",
                    "remote_interface_id": "8dd5b33d-2557-48a9-8d9d-54d6d4394203",
                    "internal": false
                  }
                ]
              }
            ],
            "provides": [
              {
                "name": "network_interface",
                "type": "Protocols::NetworkInterface",
                "properties": [
                  {
                    "name": "port",
                    "form_options": {
                      "type": "integer"
                    },
                    "title": "Port"
                  }
                ]
              }
            ],
            "depends": [
              {
                "name": "subnet",
                "type": "Protocols::Subnet",
                "properties": [
                  {
                    "name": "port",
                    "form_options": {
                      "type": "integer"
                    },
                    "title": "Port"
                  }
                ]
              },
              {
                "name": "internet",
                "type": "Protocols::IP",
                "properties": [
                  {
                    "name": "port",
                    "form_options": {
                      "type": "integer"
                    },
                    "title": "Port"
                  }
                ]
              }
            ]
          },
          {
            "id": "2672f0d2-13c0-421a-9026-9b461d3d4601",
            "name": "eni-0633084d",
            "type": "Services::Network::NetworkInterface::AWS",
            "internal": true,
            "expose": true,
            "additional_properties": {
              "parent_id": "48d93535-3850-49b0-a81f-a1181c09d406",
              "depends": [
                {
                  "name": "subnet",
                  "protocol": {
                    "generated_attribute_methods": {
                      "_mutex": {}
                    },
                    "attribute_methods_generated": false,
                    "relation_delegate_cache": {
                      "ActiveRecord::Relation": {
                        "delegation_mutex": {}
                      },
                      "ActiveRecord::Associations::CollectionProxy": {
                        "delegation_mutex": {}
                      },
                      "ActiveRecord::AssociationRelation": {
                        "delegation_mutex": {}
                      }
                    },
                    "_store_accessors_module": {}
                  }
                },
                {
                  "name": "internet",
                  "protocol": {
                    "generated_attribute_methods": {
                      "_mutex": {}
                    },
                    "attribute_methods_generated": false,
                    "relation_delegate_cache": {
                      "ActiveRecord::Relation": {
                        "delegation_mutex": {}
                      },
                      "ActiveRecord::Associations::CollectionProxy": {
                        "delegation_mutex": {}
                      },
                      "ActiveRecord::AssociationRelation": {
                        "delegation_mutex": {}
                      }
                    }
                  }
                }
              ],
              "draggable": null,
              "drawable": null,
              "edge": false,
              "generic_type": "Services::Network::NetworkInterface",
              "id": "2672f0d2-13c0-421a-9026-9b461d3d4601",
              "interfaces": [
                {
                  "id": "423e3e59-545a-4bf4-a70b-5ec04249f194",
                  "service_id": "2672f0d2-13c0-421a-9026-9b461d3d4601",
                  "name": "eni-0633084d",
                  "interface_type": "Protocols::NetworkInterface",
                  "created_at": "2016-11-14T07:38:52.448Z",
                  "updated_at": "2016-11-14T07:38:52.448Z",
                  "depends": false,
                  "connections": []
                },
                {
                  "id": "30270ae6-a348-4261-b422-084cea3352a4",
                  "service_id": "2672f0d2-13c0-421a-9026-9b461d3d4601",
                  "name": null,
                  "interface_type": "Protocols::Vpc",
                  "created_at": "2016-11-14T07:39:00.142Z",
                  "updated_at": "2016-11-14T07:39:00.142Z",
                  "depends": true,
                  "connections": [
                    {
                      "id": "3eddcfec-50d9-4fc9-9320-44a6c1cc7d8b",
                      "interface_id": "30270ae6-a348-4261-b422-084cea3352a4",
                      "remote_interface_id": "c6cb2561-6369-4bc4-98f4-4850ae2063a1",
                      "created_at": "2016-11-14T07:39:00.145Z",
                      "updated_at": "2016-11-14T07:39:00.145Z",
                      "internal": false
                    }
                  ]
                },
                {
                  "id": "86442c41-e03d-411c-90e9-3d1191552b2a",
                  "service_id": "2672f0d2-13c0-421a-9026-9b461d3d4601",
                  "name": null,
                  "interface_type": "Protocols::Subnet",
                  "created_at": "2016-11-14T07:39:00.190Z",
                  "updated_at": "2016-11-14T07:39:00.190Z",
                  "depends": true,
                  "connections": [
                    {
                      "id": "ce7145c3-3a54-45f6-be77-45489012248e",
                      "interface_id": "86442c41-e03d-411c-90e9-3d1191552b2a",
                      "remote_interface_id": "31fe310c-eda2-4842-8c6d-f53cacf7257b",
                      "created_at": "2016-11-14T07:39:00.193Z",
                      "updated_at": "2016-11-14T07:39:00.193Z",
                      "internal": false
                    }
                  ]
                },
                {
                  "id": "9a1d8224-0ce7-4c6b-83f1-89ddb8ec22ec",
                  "service_id": "2672f0d2-13c0-421a-9026-9b461d3d4601",
                  "name": null,
                  "interface_type": "Protocols::SecurityGroup",
                  "created_at": "2016-11-14T07:39:00.237Z",
                  "updated_at": "2016-11-14T07:39:00.237Z",
                  "depends": true,
                  "connections": [
                    {
                      "id": "470b6ed4-9429-4344-8a62-ac74ed2a63a7",
                      "interface_id": "9a1d8224-0ce7-4c6b-83f1-89ddb8ec22ec",
                      "remote_interface_id": "a017f658-0f50-4d44-a7eb-b6b581d24d64",
                      "created_at": "2016-11-14T07:39:00.239Z",
                      "updated_at": "2016-11-14T07:39:00.239Z",
                      "internal": false
                    }
                  ]
                },
                {
                  "id": "66214ab7-1560-4dc6-a14a-1e522a52572b",
                  "service_id": "2672f0d2-13c0-421a-9026-9b461d3d4601",
                  "name": null,
                  "interface_type": "Protocols::Server",
                  "created_at": "2016-11-14T07:39:00.283Z",
                  "updated_at": "2016-11-14T07:39:00.283Z",
                  "depends": true,
                  "connections": [
                    {
                      "id": "e4ef9436-257a-4e81-81a8-e1a4a1454385",
                      "interface_id": "66214ab7-1560-4dc6-a14a-1e522a52572b",
                      "remote_interface_id": "25624f35-7e7f-427d-a4b5-41351733effd",
                      "created_at": "2016-11-14T07:39:00.286Z",
                      "updated_at": "2016-11-14T07:39:00.286Z",
                      "internal": false
                    }
                  ]
                }
              ],
              "name": "eni-0633084d",
              "state": "running",
              "primary_key": null,
              "properties": [
                {
                  "name": "private_ips",
                  "value": [
                    {
                      "privateIpAddress": "10.0.2.190",
                      "primary": "true",
                      "item": "true"
                    }
                  ],
                  "form_options": {
                    "type": "private_ips_type",
                    "options": [],
                    "required": true
                  },
                  "title": "Private Ips"
                },
                {
                  "name": "primary",
                  "value": true,
                  "form_options": {
                    "type": "hidden",
                    "required": false,
                    "readonly": "readonly"
                  },
                  "title": "Primary"
                },
                {
                  "name": "description",
                  "value": "Primary network interface",
                  "form_options": {
                    "type": "text",
                    "required": true
                  },
                  "title": "Description"
                },
                {
                  "name": "mac_address",
                  "value": "06:7d:88:e7:99:51",
                  "form_options": {
                    "type": "hidden",
                    "readonly": "readonly"
                  },
                  "title": "Mac Address"
                },
                {
                  "name": "source_dest_check",
                  "value": true,
                  "form_options": {
                    "type": "checkbox",
                    "required": false
                  },
                  "title": "Source Destination Check"
                }
              ],
              "provides": [
                {
                  "name": "network_interface",
                  "type": "Protocols::NetworkInterface",
                  "properties": [
                    {
                      "name": "port",
                      "form_options": {
                        "type": "integer"
                      },
                      "title": "Port"
                    }
                  ]
                }
              ],
              "service_type": "networkinterface",
              "type": "Services::Network::NetworkInterface::AWS",
              "vpc_id": "b4d269a4-49c5-42bf-b7c1-2818118fe6a7"
            },
            "tags": {},
            "properties": [
              {
                "name": "private_ips",
                "value": [
                  {
                    "privateIpAddress": "10.0.2.190",
                    "primary": "true",
                    "item": "true"
                  }
                ],
                "form_options": {
                  "type": "private_ips_type",
                  "options": [],
                  "required": true
                },
                "title": "Private Ips"
              },
              {
                "name": "primary",
                "value": true,
                "form_options": {
                  "type": "hidden",
                  "required": false,
                  "readonly": "readonly"
                },
                "title": "Primary"
              },
              {
                "name": "description",
                "value": "Primary network interface",
                "form_options": {
                  "type": "text",
                  "required": true
                },
                "title": "Description"
              },
              {
                "name": "mac_address",
                "value": "06:7d:88:e7:99:51",
                "form_options": {
                  "type": "hidden",
                  "readonly": "readonly"
                },
                "title": "Mac Address"
              },
              {
                "name": "source_dest_check",
                "value": true,
                "form_options": {
                  "type": "checkbox",
                  "required": false
                },
                "title": "Source Destination Check"
              }
            ],
            "interfaces": [
              {
                "id": "423e3e59-545a-4bf4-a70b-5ec04249f194",
                "name": "eni-0633084d",
                "interface_type": "Protocols::NetworkInterface",
                "depends": false,
                "connections": []
              },
              {
                "id": "30270ae6-a348-4261-b422-084cea3352a4",
                "interface_type": "Protocols::Vpc",
                "depends": true,
                "connections": [
                  {
                    "id": "3eddcfec-50d9-4fc9-9320-44a6c1cc7d8b",
                    "interface_id": "30270ae6-a348-4261-b422-084cea3352a4",
                    "remote_interface_id": "c6cb2561-6369-4bc4-98f4-4850ae2063a1",
                    "internal": false
                  }
                ]
              },
              {
                "id": "86442c41-e03d-411c-90e9-3d1191552b2a",
                "interface_type": "Protocols::Subnet",
                "depends": true,
                "connections": [
                  {
                    "id": "ce7145c3-3a54-45f6-be77-45489012248e",
                    "interface_id": "86442c41-e03d-411c-90e9-3d1191552b2a",
                    "remote_interface_id": "31fe310c-eda2-4842-8c6d-f53cacf7257b",
                    "internal": false
                  }
                ]
              },
              {
                "id": "9a1d8224-0ce7-4c6b-83f1-89ddb8ec22ec",
                "interface_type": "Protocols::SecurityGroup",
                "depends": true,
                "connections": [
                  {
                    "id": "470b6ed4-9429-4344-8a62-ac74ed2a63a7",
                    "interface_id": "9a1d8224-0ce7-4c6b-83f1-89ddb8ec22ec",
                    "remote_interface_id": "a017f658-0f50-4d44-a7eb-b6b581d24d64",
                    "internal": false
                  }
                ]
              },
              {
                "id": "66214ab7-1560-4dc6-a14a-1e522a52572b",
                "interface_type": "Protocols::Server",
                "depends": true,
                "connections": [
                  {
                    "id": "e4ef9436-257a-4e81-81a8-e1a4a1454385",
                    "interface_id": "66214ab7-1560-4dc6-a14a-1e522a52572b",
                    "remote_interface_id": "25624f35-7e7f-427d-a4b5-41351733effd",
                    "internal": false
                  }
                ]
              }
            ],
            "provides": [
              {
                "name": "network_interface",
                "type": "Protocols::NetworkInterface",
                "properties": [
                  {
                    "name": "port",
                    "form_options": {
                      "type": "integer"
                    },
                    "title": "Port"
                  }
                ]
              }
            ],
            "depends": [
              {
                "name": "subnet",
                "type": "Protocols::Subnet",
                "properties": [
                  {
                    "name": "port",
                    "form_options": {
                      "type": "integer"
                    },
                    "title": "Port"
                  }
                ]
              },
              {
                "name": "internet",
                "type": "Protocols::IP",
                "properties": [
                  {
                    "name": "port",
                    "form_options": {
                      "type": "integer"
                    },
                    "title": "Port"
                  }
                ]
              }
            ]
          }
        ],
        "description": "",  
        "_links": {}
      }';
    
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
