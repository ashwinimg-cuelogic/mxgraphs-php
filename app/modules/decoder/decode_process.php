<?php
  include_once(BASE_DIR."src/mxServer.php");
  include_once(BASE_DIR. 'app\factories\service.factory.php');
  include_once(BASE_DIR. 'app\modules\decoder\service_configuration.php');
  include_once(BASE_DIR. 'app\modules\decoder\template_model.php');

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
      $this->service_factory->clearLog();

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
      
      /*if(0) {
        $jsonString = '{
          "id": "488019c0-27a9-450e-bc4c-19fb49cbfbc7",
          "name": "ashwini-nfs-iscsi",
          "state": "pending",
          "template_model": {
            "scale": "1"
          },
          "adapter_id": "906b379e-3f78-4ade-bc64-587a8919bd78",
          "region_id": "b0459d7c-ea81-49b2-ad43-1dbb2d82ac02",
          "created_by": "Demo",
          "created_at": "2016-12-23 06:06 UTC",
          "updated_by": "Demo",
          "updated_at": "2016-12-23 06:06 UTC",
          "adapter_type": "AWS",
          "adapter_name": "pratik-067",
          "region_name": "Asia Pacific (Singapore)",
          "shared_with": [],
          "description": "",
          "services": [
            {
              "id": "3451e196-2829-4487-a45e-68467dbe39e7",
              "name": "atit-VPC",
              "type": "Services::Vpc",
              "geometry": {
                "x": "50",
                "y": "40",
                "width": "660",
                "height": "420"
              },
              "additional_properties": {
                "id": "3451e196-2829-4487-a45e-68467dbe39e7",
                "edge": false,
                "service_type": "vpc",
                "generic_type": "Services::Vpc",
                "type": "Services::Vpc",
                "name": "atit-VPC",
                "drawable": true,
                "draggable": true,
                "interfaces": [
                  {
                    "id": "3312d244-9077-4361-bc96-bc118963a6e7",
                    "name": "atit-VPC",
                    "depends": false,
                    "connections": null,
                    "interface_type": "Protocols::Vpc"
                  }
                ],
                "vpc_id": "",
                "primary_key": "b4d269a4-49c5-42bf-b7c1-2818118fe6a7",
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
                "depends": null,
                "parent_id": null,
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
                    "value": true,
                    "form_options": {
                      "type": "hidden"
                    },
                    "title": "Enable Dns Resolution"
                  },
                  {
                    "name": "enable_dns_hostnames",
                    "value": false,
                    "form_options": {
                      "type": "hidden"
                    },
                    "title": "Enable Dns Hostnames"
                  }
                ]
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
                  "value": true,
                  "form_options": {
                    "type": "hidden"
                  },
                  "title": "Enable Dns Resolution"
                },
                {
                  "name": "enable_dns_hostnames",
                  "value": false,
                  "form_options": {
                    "type": "hidden"
                  },
                  "title": "Enable Dns Hostnames"
                }
              ],
              "interfaces": [
                {
                  "id": "3312d244-9077-4361-bc96-bc118963a6e7",
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
              "id": "e4ace307-fe53-452b-9ed2-d2512102a438",
              "name": "default",
              "type": "Services::Network::SecurityGroup::AWS",
              "geometry": {
                "x": "50",
                "y": "40",
                "width": "0",
                "height": "0"
              },
              "additional_properties": {
                "id": "e4ace307-fe53-452b-9ed2-d2512102a438",
                "edge": false,
                "service_type": "SecurityGroup",
                "generic_type": "Services::Network::SecurityGroup",
                "type": "Services::Network::SecurityGroup::AWS",
                "name": "default",
                "drawable": false,
                "draggable": false,
                "interfaces": [
                  {
                    "id": "3c9feedc-c018-4837-8645-e0c4a1112910",
                    "name": "default",
                    "depends": false,
                    "connections": null,
                    "interface_type": "Protocols::SecurityGroup"
                  },
                  {
                    "id": "0d0e0938-72d1-431c-9aff-05f49a9e7f6b",
                    "name": "atit-VPC",
                    "depends": true,
                    "connections": [
                      {
                        "id": "1749a888-b6ca-456c-9999-031c3512c6fa",
                        "interface_id": "0d0e0938-72d1-431c-9aff-05f49a9e7f6b",
                        "remote_interface_id": "3312d244-9077-4361-bc96-bc118963a6e7",
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
                "parent_id": "3451e196-2829-4487-a45e-68467dbe39e7",
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
                        "ipRanges": null,
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
                        "groups": null,
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
                      "ipRanges": null,
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
                      "groups": null,
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
                  "id": "3c9feedc-c018-4837-8645-e0c4a1112910",
                  "name": "default",
                  "interface_type": "Protocols::SecurityGroup",
                  "depends": false,
                  "connections": []
                },
                {
                  "id": "0d0e0938-72d1-431c-9aff-05f49a9e7f6b",
                  "name": "atit-VPC",
                  "interface_type": "Protocols::Vpc",
                  "depends": true,
                  "connections": [
                    {
                      "id": "1749a888-b6ca-456c-9999-031c3512c6fa",
                      "interface_id": "0d0e0938-72d1-431c-9aff-05f49a9e7f6b",
                      "remote_interface_id": "3312d244-9077-4361-bc96-bc118963a6e7",
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
              "id": "da264b00-0f81-4212-8214-93d80cf38990",
              "name": "availability_zone1",
              "type": "Services::Network::AvailabilityZone",
              "geometry": {
                "x": "120",
                "y": "110",
                "width": "280",
                "height": "280"
              },
              "additional_properties": {
                "id": "da264b00-0f81-4212-8214-93d80cf38990",
                "numbered": false,
                "edge": false,
                "service_type": "availabilityzone",
                "generic_type": "Services::Network::AvailabilityZone",
                "type": "Services::Network::AvailabilityZone",
                "name": "availability_zone1",
                "drawable": true,
                "draggable": true,
                "interfaces": [
                  {
                    "id": "06d1c513-6f2c-48a1-857f-35cabe35e356",
                    "name": "availability_zone",
                    "depends": false,
                    "connections": null,
                    "interface_type": "Protocols::AvailabilityZone"
                  },
                  {
                    "id": "625316bb-e00c-4e24-9dba-deec49208548",
                    "name": "atit-VPC",
                    "depends": true,
                    "connections": [
                      {
                        "id": "7420604d-4722-4c09-8bf9-90259aa57d2d",
                        "interface_id": "625316bb-e00c-4e24-9dba-deec49208548",
                        "remote_interface_id": "3312d244-9077-4361-bc96-bc118963a6e7",
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
                "depends": null,
                "parent_id": "3451e196-2829-4487-a45e-68467dbe39e7",
                "properties": [
                  {
                    "name": "code",
                    "value": "ap-southeast-1a",
                    "form_options": {
                      "type": "select",
                      "options": [
                        "ap-southeast-1a",
                        "ap-southeast-1b"
                      ]
                    },
                    "title": "Availability Zone Name"
                  }
                ]
              },
              "properties": [
                {
                  "name": "code",
                  "value": "ap-southeast-1a",
                  "form_options": {
                    "type": "select",
                    "options": [
                      "ap-southeast-1a",
                      "ap-southeast-1b"
                    ]
                  },
                  "title": "Availability Zone Name"
                }
              ],
              "interfaces": [
                {
                  "id": "06d1c513-6f2c-48a1-857f-35cabe35e356",
                  "name": "availability_zone",
                  "interface_type": "Protocols::AvailabilityZone",
                  "depends": false,
                  "connections": []
                },
                {
                  "id": "625316bb-e00c-4e24-9dba-deec49208548",
                  "name": "atit-VPC",
                  "interface_type": "Protocols::Vpc",
                  "depends": true,
                  "connections": [
                    {
                      "id": "7420604d-4722-4c09-8bf9-90259aa57d2d",
                      "interface_id": "625316bb-e00c-4e24-9dba-deec49208548",
                      "remote_interface_id": "3312d244-9077-4361-bc96-bc118963a6e7",
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
              "id": "019d0860-4cc9-479f-be00-66886e63c19e",
              "name": "main",
              "type": "Services::Network::RouteTable::AWS",
              "geometry": {
                "x": "594",
                "y": "22",
                "width": "56",
                "height": "56"
              },
              "additional_properties": {
                "id": "019d0860-4cc9-479f-be00-66886e63c19e",
                "edge": false,
                "service_type": "RouteTable",
                "generic_type": "Services::Network::RouteTable",
                "type": "Services::Network::RouteTable::AWS",
                "name": "main",
                "drawable": true,
                "draggable": false,
                "interfaces": [
                  {
                    "id": "0b58bda7-052e-4ea5-b772-fc1f91cb4600",
                    "name": "main",
                    "depends": false,
                    "connections": null,
                    "interface_type": "Protocols::RouteTable"
                  },
                  {
                    "id": "5511301b-fe50-4ddc-8538-d906f4a9d003",
                    "name": "atit-VPC",
                    "depends": true,
                    "connections": [
                      {
                        "id": "c48439dd-0693-48a7-890a-b89cf6af8ea3",
                        "interface_id": "5511301b-fe50-4ddc-8538-d906f4a9d003",
                        "remote_interface_id": "3312d244-9077-4361-bc96-bc118963a6e7",
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
                "depends": null,
                "parent_id": "3451e196-2829-4487-a45e-68467dbe39e7",
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
                      },
                      {
                        "destinationCidrBlock": "0.0.0.0/0",
                        "connected_service": "internet_gateway",
                        "connected_service_name": "internet_gateway",
                        "connected_service_id": "78809002-b293-4d27-bb5d-f8767b2da890"
                      }
                    ],
                    "form_options": {
                      "type": "text",
                      "readonly": true
                    },
                    "title": "Routes"
                  }
                ]
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
                    },
                    {
                      "destinationCidrBlock": "0.0.0.0/0",
                      "connected_service": "internet_gateway",
                      "connected_service_name": "internet_gateway",
                      "connected_service_id": "78809002-b293-4d27-bb5d-f8767b2da890"
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
                  "id": "0b58bda7-052e-4ea5-b772-fc1f91cb4600",
                  "name": "main",
                  "interface_type": "Protocols::RouteTable",
                  "depends": false,
                  "connections": []
                },
                {
                  "id": "5511301b-fe50-4ddc-8538-d906f4a9d003",
                  "name": "atit-VPC",
                  "interface_type": "Protocols::Vpc",
                  "depends": true,
                  "connections": [
                    {
                      "id": "c48439dd-0693-48a7-890a-b89cf6af8ea3",
                      "interface_id": "5511301b-fe50-4ddc-8538-d906f4a9d003",
                      "remote_interface_id": "3312d244-9077-4361-bc96-bc118963a6e7",
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
              "id": "78809002-b293-4d27-bb5d-f8767b2da890",
              "name": "internet_gateway",
              "type": "Services::Network::InternetGateway::AWS",
              "geometry": {
                "x": "-28",
                "y": "40",
                "width": "56",
                "height": "56"
              },
              "additional_properties": {
                "id": "78809002-b293-4d27-bb5d-f8767b2da890",
                "edge": false,
                "service_type": "InternetGateway",
                "generic_type": "Services::Network::InternetGateway",
                "type": "Services::Network::InternetGateway::AWS",
                "name": "internet_gateway",
                "drawable": true,
                "draggable": false,
                "interfaces": [
                  {
                    "id": "99ec1345-72d7-4855-ab90-fa52d480b040",
                    "name": "internet_gateway",
                    "depends": false,
                    "connections": null,
                    "interface_type": "Protocols::InternetGateway"
                  },
                  {
                    "id": "2256fd39-eab4-4ce7-ac03-1a13c9fa4b0f",
                    "name": "atit-VPC",
                    "depends": true,
                    "connections": [
                      {
                        "id": "116be0b5-1fcb-4d2c-b188-601793ffcd7a",
                        "interface_id": "2256fd39-eab4-4ce7-ac03-1a13c9fa4b0f",
                        "remote_interface_id": "3312d244-9077-4361-bc96-bc118963a6e7",
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
                "depends": null,
                "parent_id": "3451e196-2829-4487-a45e-68467dbe39e7",
                "properties": [
                  {
                    "name": "internet_gateway_id",
                    "value": "igw-3826655d",
                    "form_options": {
                      "type": "hidden"
                    },
                    "title": "Internet Gateway Id"
                  }
                ]
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
                  "id": "99ec1345-72d7-4855-ab90-fa52d480b040",
                  "name": "internet_gateway",
                  "interface_type": "Protocols::InternetGateway",
                  "depends": false,
                  "connections": []
                },
                {
                  "id": "2256fd39-eab4-4ce7-ac03-1a13c9fa4b0f",
                  "name": "atit-VPC",
                  "interface_type": "Protocols::Vpc",
                  "depends": true,
                  "connections": [
                    {
                      "id": "116be0b5-1fcb-4d2c-b188-601793ffcd7a",
                      "interface_id": "2256fd39-eab4-4ce7-ac03-1a13c9fa4b0f",
                      "remote_interface_id": "3312d244-9077-4361-bc96-bc118963a6e7",
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
              "id": "90419c59-2a4b-4821-9c39-4789dce4150d",
              "name": "atit-subnet1",
              "type": "Services::Network::Subnet::AWS",
              "geometry": {
                "x": "40",
                "y": "50",
                "width": "180",
                "height": "180"
              },
              "container": true,
              "additional_properties": {
                "id": "90419c59-2a4b-4821-9c39-4789dce4150d",
                "numbered": false,
                "edge": false,
                "service_type": "subnet",
                "generic_type": "Services::Network::Subnet",
                "type": "Services::Network::Subnet::AWS",
                "name": "atit-subnet1",
                "container": true,
                "drawable": true,
                "draggable": true,
                "interfaces": [
                  {
                    "id": "b8d194d8-b2c3-43f7-8d05-9153a3088a82",
                    "name": "subnet",
                    "depends": false,
                    "connections": null,
                    "interface_type": "Protocols::Subnet"
                  },
                  {
                    "id": "dcbb32a6-12a5-4319-a143-dacb04a82713",
                    "name": "availability_zone1",
                    "depends": true,
                    "connections": [
                      {
                        "id": "e375f72a-08de-48bb-b465-3b120096212e",
                        "interface_id": "dcbb32a6-12a5-4319-a143-dacb04a82713",
                        "remote_interface_id": "06d1c513-6f2c-48a1-857f-35cabe35e356",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::AvailabilityZone"
                  },
                  {
                    "id": "e11a9499-94fb-4aeb-b0f6-d29fb4313153",
                    "name": "atit-VPC",
                    "depends": true,
                    "connections": [
                      {
                        "id": "e5b1187d-dcb5-4a93-b339-2f36d85a19ad",
                        "interface_id": "e11a9499-94fb-4aeb-b0f6-d29fb4313153",
                        "remote_interface_id": "3312d244-9077-4361-bc96-bc118963a6e7",
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
                ],
                "parent_id": "da264b00-0f81-4212-8214-93d80cf38990",
                "properties": [
                  {
                    "name": "cidr_block",
                    "value": "10.0.32.0/19",
                    "form_options": {
                      "type": "text"
                    },
                    "title": "CIDR Block"
                  }
                ],
                "lastAssignedIP": "10.0.32.0",
                "existing_subnet": "0b400748-cced-4049-97e7-675cf551602b"
              },
              "properties": [
                {
                  "name": "cidr_block",
                  "value": "10.0.32.0/19",
                  "form_options": {
                    "type": "text"
                  },
                  "title": "CIDR Block"
                }
              ],
              "interfaces": [
                {
                  "id": "b8d194d8-b2c3-43f7-8d05-9153a3088a82",
                  "name": "subnet",
                  "interface_type": "Protocols::Subnet",
                  "depends": false,
                  "connections": []
                },
                {
                  "id": "dcbb32a6-12a5-4319-a143-dacb04a82713",
                  "name": "availability_zone1",
                  "interface_type": "Protocols::AvailabilityZone",
                  "depends": true,
                  "connections": [
                    {
                      "id": "e375f72a-08de-48bb-b465-3b120096212e",
                      "interface_id": "dcbb32a6-12a5-4319-a143-dacb04a82713",
                      "remote_interface_id": "06d1c513-6f2c-48a1-857f-35cabe35e356",
                      "internal": false
                    }
                  ]
                },
                {
                  "id": "e11a9499-94fb-4aeb-b0f6-d29fb4313153",
                  "name": "atit-VPC",
                  "interface_type": "Protocols::Vpc",
                  "depends": true,
                  "connections": [
                    {
                      "id": "e5b1187d-dcb5-4a93-b339-2f36d85a19ad",
                      "interface_id": "e11a9499-94fb-4aeb-b0f6-d29fb4313153",
                      "remote_interface_id": "3312d244-9077-4361-bc96-bc118963a6e7",
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
              "id": "074a27f4-b312-443c-a9cd-16fe44c9b33e",
              "name": "EC2-$$servername-$$function##-V20",
              "type": "Services::Compute::Server::AWS",
              "geometry": {
                "x": "40",
                "y": "50",
                "width": "56",
                "height": "56"
              },
              "additional_properties": {
                "id": "074a27f4-b312-443c-a9cd-16fe44c9b33e",
                "numbered": false,
                "edge": false,
                "service_type": "server",
                "generic_type": "Services::Compute::Server",
                "type": "Services::Compute::Server::AWS",
                "name": "EC2-$$servername-$$function##-V20",
                "drawable": true,
                "draggable": true,
                "interfaces": [
                  {
                    "id": "d172340a-6400-4695-8a26-3cbeb9e20418",
                    "name": "default",
                    "depends": true,
                    "connections": [
                      {
                        "id": "a6de13bf-ea1d-4b0c-87c9-8c199ffcc830",
                        "interface_id": "d172340a-6400-4695-8a26-3cbeb9e20418",
                        "remote_interface_id": "3c9feedc-c018-4837-8645-e0c4a1112910",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::SecurityGroup"
                  },
                  {
                    "id": "4ada22de-f58d-41cf-817d-dee2fba58eb3",
                    "name": "AMI-Test",
                    "depends": false,
                    "connections": null,
                    "interface_type": "Protocols::Server"
                  },
                  {
                    "id": "43af5a03-ba2d-4e11-9d18-437bb20f6547",
                    "name": "atit-subnet1",
                    "depends": true,
                    "connections": [
                      {
                        "id": "97a04d1a-770b-4585-8172-9b91d98c0463",
                        "interface_id": "43af5a03-ba2d-4e11-9d18-437bb20f6547",
                        "remote_interface_id": "b8d194d8-b2c3-43f7-8d05-9153a3088a82",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::Subnet"
                  },
                  {
                    "id": "b1de6b03-4b6a-48f6-b406-79202d4e7906",
                    "name": "atit-VPC",
                    "depends": true,
                    "connections": [
                      {
                        "id": "09b002fe-8586-4dae-8b74-fb55071ee85c",
                        "interface_id": "b1de6b03-4b6a-48f6-b406-79202d4e7906",
                        "remote_interface_id": "3312d244-9077-4361-bc96-bc118963a6e7",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::Vpc"
                  },
                  {
                    "id": "2c7f4bd1-c662-4e94-a2b0-6deb5887425b",
                    "name": "VOL-$$serviceowner-$$function##-V20-",
                    "depends": true,
                    "connections": [
                      {
                        "id": "80755041-f2f5-4919-8a27-fbf113410293",
                        "interface_id": "2c7f4bd1-c662-4e94-a2b0-6deb5887425b",
                        "remote_interface_id": "cb9dab25-aa5f-4aec-a920-d0b44a8d7db8",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::Disk"
                  }
                ],
                "vpc_id": "b4d269a4-49c5-42bf-b7c1-2818118fe6a7",
                "primary_key": "",
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
                ],
                "parent_id": "90419c59-2a4b-4821-9c39-4789dce4150d",
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
                    "value": "ami-72621c20",
                    "form_options": {
                      "type": "text",
                      "readonly": "readonly"
                    },
                    "title": "AMI"
                  },
                  {
                    "name": "image_config_id",
                    "value": "70d778f5-2309-4784-8f62-1cd801399b6e",
                    "form_options": {
                      "type": "select",
                      "options": {
                        "70d778f5-2309-4784-8f62-1cd801399b6e": "q1"
                      },
                      "flavor_ids": {
                        "70d778f5-2309-4784-8f62-1cd801399b6e": [
                          "t1.micro"
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
                    "value": "t1.micro",
                    "form_options": {
                      "type": "select",
                      "options": [
                        "t1.micro"
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
                    "form_options": {
                      "type": "hidden",
                      "required": false
                    },
                    "title": "Private IP Address"
                  },
                  {
                    "name": "virtualization_type",
                    "value": "paravirtual",
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
                    "value": false,
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
                    "value": false,
                    "form_options": {
                      "type": "checkbox",
                      "required": false
                    },
                    "title": "EBS Optimized"
                  },
                  {
                    "name": "instance_monitoring",
                    "value": false,
                    "form_options": {
                      "type": "checkbox",
                      "required": false
                    },
                    "title": "Instance Monitoring"
                  },
                  {
                    "name": "key_name",
                    "value": "",
                    "form_options": {
                      "type": "select",
                      "options": [
                        "asdsad"
                      ],
                      "data": [
                        "asdsad"
                      ],
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
                        "Ebs.VolumeType": "standard",
                        "Ebs.Iops": "24",
                        "Ebs.DeleteOnTermination": true,
                        "DevicePath": "/dev/sda1",
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
                    "value": [
                      {
                        "filer_id": "86e0fd2d-3460-458f-b168-23a2e1de0831",
                        "source": "/data/d0",
                        "destination": "/data"
                      }
                    ],
                    "form_options": {
                      "type": "hidden",
                      "readonly": "readonly",
                      "options": [
                        {
                          "id": "86e0fd2d-3460-458f-b168-23a2e1de0831",
                          "name": "ashwinig test",
                          "adapter_id": "906b379e-3f78-4ade-bc64-587a8919bd78",
                          "region_id": "b0459d7c-ea81-49b2-ad43-1dbb2d82ac02",
                          "vpc_id": "b4d269a4-49c5-42bf-b7c1-2818118fe6a7",
                          "security_group_id": "42204545-cba5-4285-9ee6-4ec0dac844e6",
                          "account_id": "e7028ea2-4bd7-428b-b538-1bf0bfb4cb76",
                          "data": {
                            "address": "0.0.0.12"
                          },
                          "created_at": "2016-12-23T05:57:28.929Z",
                          "updated_at": "2016-12-23T05:57:28.929Z"
                        }
                      ],
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
                    "value": "",
                    "form_options": {
                      "type": "select",
                      "options": [
                        ""
                      ],
                      "keepfirstblank": true,
                      "firstblanktext": "None",
                      "required": false
                    },
                    "title": "IAM Role"
                  }
                ]
              },
              "display_name": "AMI-Test",
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
                  "value": "ami-72621c20",
                  "form_options": {
                    "type": "text",
                    "readonly": "readonly"
                  },
                  "title": "AMI"
                },
                {
                  "name": "image_config_id",
                  "value": "70d778f5-2309-4784-8f62-1cd801399b6e",
                  "form_options": {
                    "type": "select",
                    "options": {
                      "70d778f5-2309-4784-8f62-1cd801399b6e": "q1"
                    },
                    "flavor_ids": {
                      "70d778f5-2309-4784-8f62-1cd801399b6e": [
                        "t1.micro"
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
                  "value": "t1.micro",
                  "form_options": {
                    "type": "select",
                    "options": [
                      "t1.micro"
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
                  "form_options": {
                    "type": "hidden",
                    "required": false
                  },
                  "title": "Private IP Address"
                },
                {
                  "name": "virtualization_type",
                  "value": "paravirtual",
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
                  "value": false,
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
                  "value": false,
                  "form_options": {
                    "type": "checkbox",
                    "required": false
                  },
                  "title": "EBS Optimized"
                },
                {
                  "name": "instance_monitoring",
                  "value": false,
                  "form_options": {
                    "type": "checkbox",
                    "required": false
                  },
                  "title": "Instance Monitoring"
                },
                {
                  "name": "key_name",
                  "value": "",
                  "form_options": {
                    "type": "select",
                    "options": [
                      "asdsad"
                    ],
                    "data": [
                      "asdsad"
                    ],
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
                      "Ebs.VolumeType": "standard",
                      "Ebs.Iops": "24",
                      "Ebs.DeleteOnTermination": true,
                      "DevicePath": "/dev/sda1",
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
                  "value": [
                    {
                      "filer_id": "86e0fd2d-3460-458f-b168-23a2e1de0831",
                      "source": "/data/d0",
                      "destination": "/data"
                    }
                  ],
                  "form_options": {
                    "type": "hidden",
                    "readonly": "readonly",
                    "options": [
                      {
                        "id": "86e0fd2d-3460-458f-b168-23a2e1de0831",
                        "name": "ashwinig test",
                        "adapter_id": "906b379e-3f78-4ade-bc64-587a8919bd78",
                        "region_id": "b0459d7c-ea81-49b2-ad43-1dbb2d82ac02",
                        "vpc_id": "b4d269a4-49c5-42bf-b7c1-2818118fe6a7",
                        "security_group_id": "42204545-cba5-4285-9ee6-4ec0dac844e6",
                        "account_id": "e7028ea2-4bd7-428b-b538-1bf0bfb4cb76",
                        "data": {
                          "address": "0.0.0.12"
                        },
                        "created_at": "2016-12-23T05:57:28.929Z",
                        "updated_at": "2016-12-23T05:57:28.929Z"
                      }
                    ],
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
                  "value": "",
                  "form_options": {
                    "type": "select",
                    "options": [
                      ""
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
                  "id": "d172340a-6400-4695-8a26-3cbeb9e20418",
                  "name": "default",
                  "interface_type": "Protocols::SecurityGroup",
                  "depends": true,
                  "connections": [
                    {
                      "id": "a6de13bf-ea1d-4b0c-87c9-8c199ffcc830",
                      "interface_id": "d172340a-6400-4695-8a26-3cbeb9e20418",
                      "remote_interface_id": "3c9feedc-c018-4837-8645-e0c4a1112910",
                      "internal": false
                    }
                  ]
                },
                {
                  "id": "4ada22de-f58d-41cf-817d-dee2fba58eb3",
                  "name": "AMI-Test",
                  "interface_type": "Protocols::Server",
                  "depends": false,
                  "connections": []
                },
                {
                  "id": "43af5a03-ba2d-4e11-9d18-437bb20f6547",
                  "name": "atit-subnet1",
                  "interface_type": "Protocols::Subnet",
                  "depends": true,
                  "connections": [
                    {
                      "id": "97a04d1a-770b-4585-8172-9b91d98c0463",
                      "interface_id": "43af5a03-ba2d-4e11-9d18-437bb20f6547",
                      "remote_interface_id": "b8d194d8-b2c3-43f7-8d05-9153a3088a82",
                      "internal": false
                    }
                  ]
                },
                {
                  "id": "b1de6b03-4b6a-48f6-b406-79202d4e7906",
                  "name": "atit-VPC",
                  "interface_type": "Protocols::Vpc",
                  "depends": true,
                  "connections": [
                    {
                      "id": "09b002fe-8586-4dae-8b74-fb55071ee85c",
                      "interface_id": "b1de6b03-4b6a-48f6-b406-79202d4e7906",
                      "remote_interface_id": "3312d244-9077-4361-bc96-bc118963a6e7",
                      "internal": false
                    }
                  ]
                },
                {
                  "id": "2c7f4bd1-c662-4e94-a2b0-6deb5887425b",
                  "name": "VOL-$$serviceowner-$$function##-V20-",
                  "interface_type": "Protocols::Disk",
                  "depends": true,
                  "connections": [
                    {
                      "id": "80755041-f2f5-4919-8a27-fbf113410293",
                      "interface_id": "2c7f4bd1-c662-4e94-a2b0-6deb5887425b",
                      "remote_interface_id": "cb9dab25-aa5f-4aec-a920-d0b44a8d7db8",
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
              "id": "d319afd8-9016-4776-bb6e-e046034b9d41",
              "name": "VOL-$$serviceowner-$$function##-V20-",
              "type": "Services::Compute::Server::Volume::AWS",
              "geometry": {
                "x": "10",
                "y": "21",
                "width": "25",
                "height": "25"
              },
              "internal": true,
              "additional_properties": {
                "id": "d319afd8-9016-4776-bb6e-e046034b9d41",
                "numbered": false,
                "edge": false,
                "service_type": "Volume",
                "generic_type": "Services::Compute::Server::Volume",
                "type": "Services::Compute::Server::Volume::AWS",
                "name": "VOL-$$serviceowner-$$function##-V20-",
                "internal": true,
                "drawable": false,
                "draggable": true,
                "interfaces": [
                  {
                    "id": "cb9dab25-aa5f-4aec-a920-d0b44a8d7db8",
                    "name": "volume",
                    "depends": false,
                    "connections": null,
                    "interface_type": "Protocols::Disk"
                  },
                  {
                    "id": "1fd930a0-bf91-42a2-85a5-6499a040c8c3",
                    "name": "availability_zone1",
                    "depends": true,
                    "connections": [
                      {
                        "id": "963cd850-2ff2-4064-a3a7-6e0d1643665d",
                        "interface_id": "1fd930a0-bf91-42a2-85a5-6499a040c8c3",
                        "remote_interface_id": "06d1c513-6f2c-48a1-857f-35cabe35e356",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::AvailabilityZone"
                  }
                ],
                "vpc_id": "b4d269a4-49c5-42bf-b7c1-2818118fe6a7",
                "primary_key": "",
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
                "depends": null,
                "parent_id": "074a27f4-b312-443c-a9cd-16fe44c9b33e",
                "properties": [
                  {
                    "name": "size",
                    "value": "8",
                    "form_options": {
                      "type": "range",
                      "min": "1",
                      "max": "1024",
                      "step": "1"
                    },
                    "title": "Size"
                  },
                  {
                    "name": "volume_type",
                    "value": "Magnetic",
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
                    "value": "/dev/sda1",
                    "form_options": {
                      "type": "text"
                    },
                    "title": "Device Path"
                  },
                  {
                    "name": "delete_on_termination",
                    "value": false,
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
                    "value": true,
                    "form_options": {
                      "type": "hidden",
                      "readonly": "readonly"
                    },
                    "title": "Configured With AMI"
                  },
                  {
                    "name": "create_from_snapshot",
                    "value": false,
                    "form_options": {
                      "environment_with_snapshots_exists": false,
                      "unallocated_snapshots_exists": false,
                      "type": "checkbox"
                    },
                    "title": "Create From Snapshot"
                  },
                  {
                    "name": "snapshot_id",
                    "value": "",
                    "form_options": {
                      "type": "select",
                      "options": []
                    },
                    "title": "Snapshot ID"
                  },
                  {
                    "name": "snapshot_provider_id",
                    "value": "",
                    "form_options": {
                      "type": "hidden",
                      "readonly": "readonly"
                    },
                    "title": "Snapshot Provider ID"
                  },
                  {
                    "name": "snapshot_environment_id",
                    "value": "",
                    "form_options": {
                      "type": "select",
                      "options": []
                    },
                    "title": "Snapshot Environment ID"
                  }
                ]
              },
              "properties": [
                {
                  "name": "size",
                  "value": "8",
                  "form_options": {
                    "type": "range",
                    "min": "1",
                    "max": "1024",
                    "step": "1"
                  },
                  "title": "Size"
                },
                {
                  "name": "volume_type",
                  "value": "Magnetic",
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
                  "value": "/dev/sda1",
                  "form_options": {
                    "type": "text"
                  },
                  "title": "Device Path"
                },
                {
                  "name": "delete_on_termination",
                  "value": false,
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
                  "value": true,
                  "form_options": {
                    "type": "hidden",
                    "readonly": "readonly"
                  },
                  "title": "Configured With AMI"
                },
                {
                  "name": "create_from_snapshot",
                  "value": false,
                  "form_options": {
                    "environment_with_snapshots_exists": false,
                    "unallocated_snapshots_exists": false,
                    "type": "checkbox"
                  },
                  "title": "Create From Snapshot"
                },
                {
                  "name": "snapshot_id",
                  "value": "",
                  "form_options": {
                    "type": "select",
                    "options": []
                  },
                  "title": "Snapshot ID"
                },
                {
                  "name": "snapshot_provider_id",
                  "value": "",
                  "form_options": {
                    "type": "hidden",
                    "readonly": "readonly"
                  },
                  "title": "Snapshot Provider ID"
                },
                {
                  "name": "snapshot_environment_id",
                  "value": "",
                  "form_options": {
                    "type": "select",
                    "options": []
                  },
                  "title": "Snapshot Environment ID"
                }
              ],
              "interfaces": [
                {
                  "id": "cb9dab25-aa5f-4aec-a920-d0b44a8d7db8",
                  "name": "volume",
                  "interface_type": "Protocols::Disk",
                  "depends": false,
                  "connections": []
                },
                {
                  "id": "1fd930a0-bf91-42a2-85a5-6499a040c8c3",
                  "name": "availability_zone1",
                  "interface_type": "Protocols::AvailabilityZone",
                  "depends": true,
                  "connections": [
                    {
                      "id": "963cd850-2ff2-4064-a3a7-6e0d1643665d",
                      "interface_id": "1fd930a0-bf91-42a2-85a5-6499a040c8c3",
                      "remote_interface_id": "06d1c513-6f2c-48a1-857f-35cabe35e356",
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
              "id": "4b5f1eea-acbd-481f-accf-231e14f3ea5c",
              "name": "eni1",
              "type": "Services::Network::NetworkInterface::AWS",
              "geometry": {
                "x": "10",
                "y": "23",
                "width": "0",
                "height": "0"
              },
              "internal": true,
              "expose": true,
              "additional_properties": {
                "id": "4b5f1eea-acbd-481f-accf-231e14f3ea5c",
                "numbered": false,
                "edge": false,
                "service_type": "NetworkInterface",
                "generic_type": "Services::Network::NetworkInterface",
                "type": "Services::Network::NetworkInterface::AWS",
                "name": "eni1",
                "internal": true,
                "drawable": true,
                "draggable": true,
                "interfaces": [
                  {
                    "id": "f49cf351-a84e-49af-ac6f-ed244925a1f1",
                    "name": "AMI-Test",
                    "depends": true,
                    "connections": [
                      {
                        "id": "0e081d5b-a1a0-49e1-86ef-fbf1c5a5d0d0",
                        "interface_id": "f49cf351-a84e-49af-ac6f-ed244925a1f1",
                        "remote_interface_id": "4ada22de-f58d-41cf-817d-dee2fba58eb3",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::Server"
                  },
                  {
                    "id": "95293cf2-da9d-4431-ae6c-ac416a9c9399",
                    "name": "default",
                    "depends": true,
                    "connections": [
                      {
                        "id": "37d7d6d2-37ca-4940-8186-8dcbd407e19b",
                        "interface_id": "95293cf2-da9d-4431-ae6c-ac416a9c9399",
                        "remote_interface_id": "3c9feedc-c018-4837-8645-e0c4a1112910",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::SecurityGroup"
                  },
                  {
                    "id": "74890365-daa8-4b36-8fed-8de8014cc091",
                    "name": "eni",
                    "depends": false,
                    "connections": null,
                    "interface_type": "Protocols::NetworkInterface"
                  },
                  {
                    "id": "40f13687-11b6-46eb-8ee4-2d34c255cd66",
                    "name": "atit-subnet1",
                    "depends": true,
                    "connections": [
                      {
                        "id": "35ed50c4-6c57-418e-89ca-517fe7f68167",
                        "interface_id": "40f13687-11b6-46eb-8ee4-2d34c255cd66",
                        "remote_interface_id": "b8d194d8-b2c3-43f7-8d05-9153a3088a82",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::Subnet"
                  },
                  {
                    "id": "434236d5-c1e1-4883-90b3-845398d08735",
                    "name": "atit-VPC",
                    "depends": true,
                    "connections": [
                      {
                        "id": "cecb62fe-a119-4343-a3c6-41ecdffbc5c8",
                        "interface_id": "434236d5-c1e1-4883-90b3-845398d08735",
                        "remote_interface_id": "3312d244-9077-4361-bc96-bc118963a6e7",
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
                ],
                "expose": true,
                "parent_id": "90419c59-2a4b-4821-9c39-4789dce4150d",
                "properties": [
                  {
                    "name": "private_ips",
                    "value": [
                      {
                        "primary": "true",
                        "privateIpAddress": "",
                        "elasticIp": "",
                        "hasElasticIP": false
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
                    "value": "",
                    "form_options": {
                      "type": "text",
                      "required": true
                    },
                    "title": "Description"
                  },
                  {
                    "name": "mac_address",
                    "value": "",
                    "form_options": {
                      "type": "hidden",
                      "readonly": "readonly"
                    },
                    "title": "Mac Address"
                  },
                  {
                    "name": "source_dest_check",
                    "form_options": {
                      "type": "checkbox",
                      "required": false
                    },
                    "title": "Source Destination Check"
                  }
                ]
              },
              "properties": [
                {
                  "name": "private_ips",
                  "value": [
                    {
                      "primary": "true",
                      "privateIpAddress": "",
                      "elasticIp": "",
                      "hasElasticIP": false
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
                  "value": "",
                  "form_options": {
                    "type": "text",
                    "required": true
                  },
                  "title": "Description"
                },
                {
                  "name": "mac_address",
                  "value": "",
                  "form_options": {
                    "type": "hidden",
                    "readonly": "readonly"
                  },
                  "title": "Mac Address"
                },
                {
                  "name": "source_dest_check",
                  "form_options": {
                    "type": "checkbox",
                    "required": false
                  },
                  "title": "Source Destination Check"
                }
              ],
              "interfaces": [
                {
                  "id": "f49cf351-a84e-49af-ac6f-ed244925a1f1",
                  "name": "AMI-Test",
                  "interface_type": "Protocols::Server",
                  "depends": true,
                  "connections": [
                    {
                      "id": "0e081d5b-a1a0-49e1-86ef-fbf1c5a5d0d0",
                      "interface_id": "f49cf351-a84e-49af-ac6f-ed244925a1f1",
                      "remote_interface_id": "4ada22de-f58d-41cf-817d-dee2fba58eb3",
                      "internal": false
                    }
                  ]
                },
                {
                  "id": "95293cf2-da9d-4431-ae6c-ac416a9c9399",
                  "name": "default",
                  "interface_type": "Protocols::SecurityGroup",
                  "depends": true,
                  "connections": [
                    {
                      "id": "37d7d6d2-37ca-4940-8186-8dcbd407e19b",
                      "interface_id": "95293cf2-da9d-4431-ae6c-ac416a9c9399",
                      "remote_interface_id": "3c9feedc-c018-4837-8645-e0c4a1112910",
                      "internal": false
                    }
                  ]
                },
                {
                  "id": "74890365-daa8-4b36-8fed-8de8014cc091",
                  "name": "eni",
                  "interface_type": "Protocols::NetworkInterface",
                  "depends": false,
                  "connections": []
                },
                {
                  "id": "40f13687-11b6-46eb-8ee4-2d34c255cd66",
                  "name": "atit-subnet1",
                  "interface_type": "Protocols::Subnet",
                  "depends": true,
                  "connections": [
                    {
                      "id": "35ed50c4-6c57-418e-89ca-517fe7f68167",
                      "interface_id": "40f13687-11b6-46eb-8ee4-2d34c255cd66",
                      "remote_interface_id": "b8d194d8-b2c3-43f7-8d05-9153a3088a82",
                      "internal": false
                    }
                  ]
                },
                {
                  "id": "434236d5-c1e1-4883-90b3-845398d08735",
                  "name": "atit-VPC",
                  "interface_type": "Protocols::Vpc",
                  "depends": true,
                  "connections": [
                    {
                      "id": "cecb62fe-a119-4343-a3c6-41ecdffbc5c8",
                      "interface_id": "434236d5-c1e1-4883-90b3-845398d08735",
                      "remote_interface_id": "3312d244-9077-4361-bc96-bc118963a6e7",
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
              "id": "9e3f896c-459d-4ced-9478-6a80a69bef16",
              "name": "ISV-V20-##-",
              "type": "Services::Compute::Server::IscsiVolume::AWS",
              "geometry": {
                "x": "10",
                "y": "18",
                "width": "56",
                "height": "56"
              },
              "internal": true,
              "additional_properties": {
                "id": "9e3f896c-459d-4ced-9478-6a80a69bef16",
                "numbered": false,
                "edge": false,
                "service_type": "iscsivolume",
                "generic_type": "Services::Compute::Server::IscsiVolume",
                "type": "Services::Compute::Server::IscsiVolume::AWS",
                "name": "ISV-V20-##-",
                "internal": true,
                "drawable": false,
                "draggable": true,
                "interfaces": [
                  {
                    "id": "53ae71b1-74f6-4e7a-82f0-74e4cd83c474",
                    "name": "ISCSI Volume",
                    "depends": false,
                    "connections": null,
                    "interface_type": "Protocols::IscsiVolume"
                  },
                  {
                    "id": "4d702e8b-ed4f-4c39-9d2a-c3fb0c6df67b",
                    "name": "EC2-$$servername-$$function##-V20",
                    "depends": true,
                    "connections": [
                      {
                        "id": "4a505edc-a64b-4c37-a634-737b6f632f35",
                        "interface_id": "4d702e8b-ed4f-4c39-9d2a-c3fb0c6df67b",
                        "remote_interface_id": "4ada22de-f58d-41cf-817d-dee2fba58eb3",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::Server"
                  },
                  {
                    "id": "e96462c8-c8db-4454-a204-cf00fcdb1345",
                    "name": "atit-VPC",
                    "depends": true,
                    "connections": [
                      {
                        "id": "4bbbabee-6765-467c-9348-6f232dfae24f",
                        "interface_id": "e96462c8-c8db-4454-a204-cf00fcdb1345",
                        "remote_interface_id": "3312d244-9077-4361-bc96-bc118963a6e7",
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
                    "name": "iscsivolume",
                    "type": "Protocols::IscsiVolume",
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
                "parent_id": "074a27f4-b312-443c-a9cd-16fe44c9b33e",
                "properties": [
                  {
                    "name": "target_ip",
                    "value": "12.12.12.12",
                    "form_options": {
                      "type": "text"
                    },
                    "title": "Target Ip"
                  },
                  {
                    "name": "target",
                    "value": "sdfsadf",
                    "form_options": {
                      "type": "text"
                    },
                    "title": "Target"
                  },
                  {
                    "name": "username",
                    "value": "test12",
                    "form_options": {
                      "type": "text"
                    },
                    "title": "Username"
                  },
                  {
                    "name": "password",
                    "value": "test123",
                    "form_options": {
                      "type": "password"
                    },
                    "title": "Password"
                  }
                ]
              },
              "properties": [
                {
                  "name": "target_ip",
                  "value": "12.12.12.12",
                  "form_options": {
                    "type": "text"
                  },
                  "title": "Target Ip"
                },
                {
                  "name": "target",
                  "value": "sdfsadf",
                  "form_options": {
                    "type": "text"
                  },
                  "title": "Target"
                },
                {
                  "name": "username",
                  "value": "test12",
                  "form_options": {
                    "type": "text"
                  },
                  "title": "Username"
                },
                {
                  "name": "password",
                  "value": "test123",
                  "form_options": {
                    "type": "password"
                  },
                  "title": "Password"
                }
              ],
              "interfaces": [
                {
                  "id": "53ae71b1-74f6-4e7a-82f0-74e4cd83c474",
                  "name": "ISCSI Volume",
                  "interface_type": "Protocols::IscsiVolume",
                  "depends": false,
                  "connections": []
                },
                {
                  "id": "4d702e8b-ed4f-4c39-9d2a-c3fb0c6df67b",
                  "name": "EC2-$$servername-$$function##-V20",
                  "interface_type": "Protocols::Server",
                  "depends": true,
                  "connections": [
                    {
                      "id": "4a505edc-a64b-4c37-a634-737b6f632f35",
                      "interface_id": "4d702e8b-ed4f-4c39-9d2a-c3fb0c6df67b",
                      "remote_interface_id": "4ada22de-f58d-41cf-817d-dee2fba58eb3",
                      "internal": false
                    }
                  ]
                },
                {
                  "id": "e96462c8-c8db-4454-a204-cf00fcdb1345",
                  "name": "atit-VPC",
                  "interface_type": "Protocols::Vpc",
                  "depends": true,
                  "connections": [
                    {
                      "id": "4bbbabee-6765-467c-9348-6f232dfae24f",
                      "interface_id": "e96462c8-c8db-4454-a204-cf00fcdb1345",
                      "remote_interface_id": "3312d244-9077-4361-bc96-bc118963a6e7",
                      "internal": false
                    }
                  ]
                }
              ],
              "provides": [
                {
                  "name": "iscsivolume",
                  "type": "Protocols::IscsiVolume",
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
            }
          ],
          "_links": {
            "provision": {
              "href": "/templates/488019c0-27a9-450e-bc4c-19fb49cbfbc7/provision"
            },
            "clone": {
              "href": "/templates/488019c0-27a9-450e-bc4c-19fb49cbfbc7/clone"
            },
            "self": {
              "href": "/templates/488019c0-27a9-450e-bc4c-19fb49cbfbc7/destroy"
            },
            "remove": {
              "href": "/templates/488019c0-27a9-450e-bc4c-19fb49cbfbc7/destroy"
            },
            "edit": {
              "href": "/templates/488019c0-27a9-450e-bc4c-19fb49cbfbc7/destroy"
            }
          }
        }';
      } else {  
        $jsonString = '{
          "id": "55c48e1f-531a-4d78-9c6d-d68d7a7ea7c7",
          "name": "ashwinig",
          "state": "pending",
          "template_model": {
            "scale": "0.578703703703704"
          },
          "adapter_id": "906b379e-3f78-4ade-bc64-587a8919bd78",
          "region_id": "720368ad-d9f2-4bd8-8cf5-01f9c21a6807",
          "created_by": "Demo",
          "created_at": "2016-12-22 07:33 UTC",
          "updated_by": "Demo",
          "updated_at": "2016-12-22 07:34 UTC",
          "adapter_type": "AWS",
          "adapter_name": "pratik-067",
          "region_name": "US East (N. Virginia)",
          "shared_with": [
            "7bb410f7-13e5-4d6f-93ab-8f5e8c2c1010"
          ],
          "description": "",
          "services": [
            {
              "id": "9d27f097-3fe8-4907-b8aa-305ba973b6da",
              "name": "prat",
              "type": "Services::Vpc",
              "geometry": {
                "x": "160.96",
                "y": "37.28",
                "width": "980",
                "height": "1060"
              },
              "additional_properties": {
                "id": "9d27f097-3fe8-4907-b8aa-305ba973b6da",
                "edge": false,
                "service_type": "vpc",
                "generic_type": "Services::Vpc",
                "type": "Services::Vpc",
                "name": "prat",
                "drawable": true,
                "draggable": true,
                "interfaces": [
                  {
                    "id": "cbb835cb-991c-4b69-a081-195c597025bf",
                    "name": "prat",
                    "depends": false,
                    "connections": null,
                    "interface_type": "Protocols::Vpc"
                  }
                ],
                "vpc_id": "",
                "primary_key": "16ef4f23-56e2-4f45-a2e3-f20e907bbfcf",
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
                "depends": null,
                "parent_id": null,
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
                    "value": "vpc-c1e86ca6",
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
                    "value": "igw-f3d1f497",
                    "form_options": {
                      "type": "hidden"
                    },
                    "title": "Internet Gateway Id"
                  },
                  {
                    "name": "enable_dns_resolution",
                    "value": true,
                    "form_options": {
                      "type": "hidden"
                    },
                    "title": "Enable Dns Resolution"
                  },
                  {
                    "name": "enable_dns_hostnames",
                    "value": true,
                    "form_options": {
                      "type": "hidden"
                    },
                    "title": "Enable Dns Hostnames"
                  }
                ]
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
                  "value": "vpc-c1e86ca6",
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
                  "value": "igw-f3d1f497",
                  "form_options": {
                    "type": "hidden"
                  },
                  "title": "Internet Gateway Id"
                },
                {
                  "name": "enable_dns_resolution",
                  "value": true,
                  "form_options": {
                    "type": "hidden"
                  },
                  "title": "Enable Dns Resolution"
                },
                {
                  "name": "enable_dns_hostnames",
                  "value": true,
                  "form_options": {
                    "type": "hidden"
                  },
                  "title": "Enable Dns Hostnames"
                }
              ],
              "interfaces": [
                {
                  "id": "cbb835cb-991c-4b69-a081-195c597025bf",
                  "name": "prat",
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
              "id": "f9eba4ea-2f94-4216-9da9-8fac9bdec058",
              "name": "default",
              "type": "Services::Network::SecurityGroup::AWS",
              "geometry": {
                "x": "40",
                "y": "20",
                "width": "0",
                "height": "0"
              },
              "additional_properties": {
                "id": "f9eba4ea-2f94-4216-9da9-8fac9bdec058",
                "edge": false,
                "service_type": "SecurityGroup",
                "generic_type": "Services::Network::SecurityGroup",
                "type": "Services::Network::SecurityGroup::AWS",
                "name": "default",
                "drawable": false,
                "draggable": false,
                "interfaces": [
                  {
                    "id": "94c76455-6f91-4128-8db1-d90b323cf891",
                    "name": "default",
                    "depends": false,
                    "connections": null,
                    "interface_type": "Protocols::SecurityGroup"
                  },
                  {
                    "id": "5d9a1716-aed7-400d-bb58-bff3fd2b4403",
                    "name": "prat",
                    "depends": true,
                    "connections": [
                      {
                        "id": "e82470c7-97a6-43f9-9691-3d983b3ee33b",
                        "interface_id": "5d9a1716-aed7-400d-bb58-bff3fd2b4403",
                        "remote_interface_id": "cbb835cb-991c-4b69-a081-195c597025bf",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::Vpc"
                  }
                ],
                "vpc_id": "16ef4f23-56e2-4f45-a2e3-f20e907bbfcf",
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
                "parent_id": "9d27f097-3fe8-4907-b8aa-305ba973b6da",
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
                            "groupId": "sg-11c19d6a"
                          }
                        ],
                        "ipRanges": null,
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
                        "groups": null,
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
                    "value": "sg-11c19d6a",
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
                          "groupId": "sg-11c19d6a"
                        }
                      ],
                      "ipRanges": null,
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
                      "groups": null,
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
                  "value": "sg-11c19d6a",
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
                  "id": "94c76455-6f91-4128-8db1-d90b323cf891",
                  "name": "default",
                  "interface_type": "Protocols::SecurityGroup",
                  "depends": false,
                  "connections": []
                },
                {
                  "id": "5d9a1716-aed7-400d-bb58-bff3fd2b4403",
                  "name": "prat",
                  "interface_type": "Protocols::Vpc",
                  "depends": true,
                  "connections": [
                    {
                      "id": "e82470c7-97a6-43f9-9691-3d983b3ee33b",
                      "interface_id": "5d9a1716-aed7-400d-bb58-bff3fd2b4403",
                      "remote_interface_id": "cbb835cb-991c-4b69-a081-195c597025bf",
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
              "id": "32dcb870-0d4f-46cc-86a8-95cbb6d54cc4",
              "name": "availability_zone1",
              "type": "Services::Network::AvailabilityZone",
              "geometry": {
                "x": "670",
                "y": "100",
                "width": "280",
                "height": "420"
              },
              "additional_properties": {
                "id": "32dcb870-0d4f-46cc-86a8-95cbb6d54cc4",
                "numbered": false,
                "edge": false,
                "service_type": "availabilityzone",
                "generic_type": "Services::Network::AvailabilityZone",
                "type": "Services::Network::AvailabilityZone",
                "name": "availability_zone1",
                "drawable": true,
                "draggable": true,
                "interfaces": [
                  {
                    "id": "e91c2e00-47ec-44f9-8323-eb716822af0a",
                    "name": "availability_zone",
                    "depends": false,
                    "connections": null,
                    "interface_type": "Protocols::AvailabilityZone"
                  },
                  {
                    "id": "ea995fd1-7ede-4b82-8c17-e5a0bcf8848e",
                    "name": "prat",
                    "depends": true,
                    "connections": [
                      {
                        "id": "0ead30b3-6c88-4f76-ab19-240288093365",
                        "interface_id": "ea995fd1-7ede-4b82-8c17-e5a0bcf8848e",
                        "remote_interface_id": "cbb835cb-991c-4b69-a081-195c597025bf",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::Vpc"
                  }
                ],
                "vpc_id": "16ef4f23-56e2-4f45-a2e3-f20e907bbfcf",
                "primary_key": "",
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
                "depends": null,
                "parent_id": "9d27f097-3fe8-4907-b8aa-305ba973b6da",
                "properties": [
                  {
                    "name": "code",
                    "value": "us-east-1d",
                    "form_options": {
                      "type": "select",
                      "options": [
                        "us-east-1b",
                        "us-east-1c",
                        "us-east-1d",
                        "us-east-1e"
                      ]
                    },
                    "title": "Availability Zone Name"
                  }
                ]
              },
              "properties": [
                {
                  "name": "code",
                  "value": "us-east-1d",
                  "form_options": {
                    "type": "select",
                    "options": [
                      "us-east-1b",
                      "us-east-1c",
                      "us-east-1d",
                      "us-east-1e"
                    ]
                  },
                  "title": "Availability Zone Name"
                }
              ],
              "interfaces": [
                {
                  "id": "e91c2e00-47ec-44f9-8323-eb716822af0a",
                  "name": "availability_zone",
                  "interface_type": "Protocols::AvailabilityZone",
                  "depends": false,
                  "connections": []
                },
                {
                  "id": "ea995fd1-7ede-4b82-8c17-e5a0bcf8848e",
                  "name": "prat",
                  "interface_type": "Protocols::Vpc",
                  "depends": true,
                  "connections": [
                    {
                      "id": "0ead30b3-6c88-4f76-ab19-240288093365",
                      "interface_id": "ea995fd1-7ede-4b82-8c17-e5a0bcf8848e",
                      "remote_interface_id": "cbb835cb-991c-4b69-a081-195c597025bf",
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
              "id": "453b1dd5-dd10-42ff-bb88-acae525e38a9",
              "name": "availability_zone1",
              "type": "Services::Network::AvailabilityZone",
              "geometry": {
                "x": "40",
                "y": "84.4",
                "width": "280",
                "height": "905.6"
              },
              "additional_properties": {
                "id": "453b1dd5-dd10-42ff-bb88-acae525e38a9",
                "numbered": false,
                "edge": false,
                "service_type": "availabilityzone",
                "generic_type": "Services::Network::AvailabilityZone",
                "type": "Services::Network::AvailabilityZone",
                "name": "availability_zone1",
                "drawable": true,
                "draggable": true,
                "interfaces": [
                  {
                    "id": "f1629909-7efa-432b-9844-65af8bfc84c2",
                    "name": "availability_zone",
                    "depends": false,
                    "connections": null,
                    "interface_type": "Protocols::AvailabilityZone"
                  },
                  {
                    "id": "7dd2b027-7f81-4cdc-beb9-2faa2a4a01c9",
                    "name": "prat",
                    "depends": true,
                    "connections": [
                      {
                        "id": "03d72b8d-6210-4c4b-ac1c-2279a855ce96",
                        "interface_id": "7dd2b027-7f81-4cdc-beb9-2faa2a4a01c9",
                        "remote_interface_id": "cbb835cb-991c-4b69-a081-195c597025bf",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::Vpc"
                  }
                ],
                "vpc_id": "16ef4f23-56e2-4f45-a2e3-f20e907bbfcf",
                "primary_key": "",
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
                "depends": null,
                "parent_id": "9d27f097-3fe8-4907-b8aa-305ba973b6da",
                "properties": [
                  {
                    "name": "code",
                    "value": "us-east-1b",
                    "form_options": {
                      "type": "select",
                      "options": [
                        "us-east-1b",
                        "us-east-1c",
                        "us-east-1d",
                        "us-east-1e"
                      ]
                    },
                    "title": "Availability Zone Name"
                  }
                ]
              },
              "properties": [
                {
                  "name": "code",
                  "value": "us-east-1b",
                  "form_options": {
                    "type": "select",
                    "options": [
                      "us-east-1b",
                      "us-east-1c",
                      "us-east-1d",
                      "us-east-1e"
                    ]
                  },
                  "title": "Availability Zone Name"
                }
              ],
              "interfaces": [
                {
                  "id": "f1629909-7efa-432b-9844-65af8bfc84c2",
                  "name": "availability_zone",
                  "interface_type": "Protocols::AvailabilityZone",
                  "depends": false,
                  "connections": []
                },
                {
                  "id": "7dd2b027-7f81-4cdc-beb9-2faa2a4a01c9",
                  "name": "prat",
                  "interface_type": "Protocols::Vpc",
                  "depends": true,
                  "connections": [
                    {
                      "id": "03d72b8d-6210-4c4b-ac1c-2279a855ce96",
                      "interface_id": "7dd2b027-7f81-4cdc-beb9-2faa2a4a01c9",
                      "remote_interface_id": "cbb835cb-991c-4b69-a081-195c597025bf",
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
              "id": "f594d0bb-df08-41e8-8070-8c21347b2bd9",
              "name": "availability_zone1",
              "type": "Services::Network::AvailabilityZone",
              "geometry": {
                "x": "360",
                "y": "90",
                "width": "280",
                "height": "660"
              },
              "additional_properties": {
                "id": "f594d0bb-df08-41e8-8070-8c21347b2bd9",
                "numbered": false,
                "edge": false,
                "service_type": "availabilityzone",
                "generic_type": "Services::Network::AvailabilityZone",
                "type": "Services::Network::AvailabilityZone",
                "name": "availability_zone1",
                "drawable": true,
                "draggable": true,
                "interfaces": [
                  {
                    "id": "9067b337-c820-4315-8099-413cddc3d083",
                    "name": "availability_zone",
                    "depends": false,
                    "connections": null,
                    "interface_type": "Protocols::AvailabilityZone"
                  },
                  {
                    "id": "b2f29d5e-f945-46ee-8d0a-5425aea5be77",
                    "name": "prat",
                    "depends": true,
                    "connections": [
                      {
                        "id": "558c58cf-4ba7-4b0f-aeb0-5750311a6fce",
                        "interface_id": "b2f29d5e-f945-46ee-8d0a-5425aea5be77",
                        "remote_interface_id": "cbb835cb-991c-4b69-a081-195c597025bf",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::Vpc"
                  }
                ],
                "vpc_id": "16ef4f23-56e2-4f45-a2e3-f20e907bbfcf",
                "primary_key": "",
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
                "depends": null,
                "parent_id": "9d27f097-3fe8-4907-b8aa-305ba973b6da",
                "properties": [
                  {
                    "name": "code",
                    "value": "us-east-1c",
                    "form_options": {
                      "type": "select",
                      "options": [
                        "us-east-1b",
                        "us-east-1c",
                        "us-east-1d",
                        "us-east-1e"
                      ]
                    },
                    "title": "Availability Zone Name"
                  }
                ]
              },
              "properties": [
                {
                  "name": "code",
                  "value": "us-east-1c",
                  "form_options": {
                    "type": "select",
                    "options": [
                      "us-east-1b",
                      "us-east-1c",
                      "us-east-1d",
                      "us-east-1e"
                    ]
                  },
                  "title": "Availability Zone Name"
                }
              ],
              "interfaces": [
                {
                  "id": "9067b337-c820-4315-8099-413cddc3d083",
                  "name": "availability_zone",
                  "interface_type": "Protocols::AvailabilityZone",
                  "depends": false,
                  "connections": []
                },
                {
                  "id": "b2f29d5e-f945-46ee-8d0a-5425aea5be77",
                  "name": "prat",
                  "interface_type": "Protocols::Vpc",
                  "depends": true,
                  "connections": [
                    {
                      "id": "558c58cf-4ba7-4b0f-aeb0-5750311a6fce",
                      "interface_id": "b2f29d5e-f945-46ee-8d0a-5425aea5be77",
                      "remote_interface_id": "cbb835cb-991c-4b69-a081-195c597025bf",
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
              "id": "b2626b7f-4c97-4b82-b2d7-7c51bdd49ec5",
              "name": "rtb-f43c3593",
              "type": "Services::Network::RouteTable::AWS",
              "geometry": {
                "x": "914",
                "y": "22",
                "width": "56",
                "height": "56"
              },
              "additional_properties": {
                "id": "b2626b7f-4c97-4b82-b2d7-7c51bdd49ec5",
                "edge": false,
                "service_type": "RouteTable",
                "generic_type": "Services::Network::RouteTable",
                "type": "Services::Network::RouteTable::AWS",
                "name": "rtb-f43c3593",
                "drawable": true,
                "draggable": false,
                "interfaces": [
                  {
                    "id": "36fef29b-d58b-4513-a5db-2cf823b96476",
                    "name": "rtb-f43c3593",
                    "depends": false,
                    "connections": null,
                    "interface_type": "Protocols::RouteTable"
                  },
                  {
                    "id": "5cddf828-bbb9-4e04-8492-caf6cf98dd92",
                    "name": "prat",
                    "depends": true,
                    "connections": [
                      {
                        "id": "9553e1a1-71eb-4aac-91d9-110238df026d",
                        "interface_id": "5cddf828-bbb9-4e04-8492-caf6cf98dd92",
                        "remote_interface_id": "cbb835cb-991c-4b69-a081-195c597025bf",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::Vpc"
                  }
                ],
                "vpc_id": "16ef4f23-56e2-4f45-a2e3-f20e907bbfcf",
                "primary_key": "",
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
                "depends": null,
                "parent_id": "9d27f097-3fe8-4907-b8aa-305ba973b6da",
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
                    "value": "rtb-f43c3593",
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
                        "gatewayId": "igw-f3d1f497",
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
                ]
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
                  "value": "rtb-f43c3593",
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
                      "gatewayId": "igw-f3d1f497",
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
                  "id": "36fef29b-d58b-4513-a5db-2cf823b96476",
                  "name": "rtb-f43c3593",
                  "interface_type": "Protocols::RouteTable",
                  "depends": false,
                  "connections": []
                },
                {
                  "id": "5cddf828-bbb9-4e04-8492-caf6cf98dd92",
                  "name": "prat",
                  "interface_type": "Protocols::Vpc",
                  "depends": true,
                  "connections": [
                    {
                      "id": "9553e1a1-71eb-4aac-91d9-110238df026d",
                      "interface_id": "5cddf828-bbb9-4e04-8492-caf6cf98dd92",
                      "remote_interface_id": "cbb835cb-991c-4b69-a081-195c597025bf",
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
              "id": "dff80195-f6af-4c6b-bee0-204367782db6",
              "name": "RTB-V20-$$function##",
              "type": "Services::Network::RouteTable::AWS",
              "geometry": {
                "x": "570",
                "y": "800",
                "width": "56",
                "height": "56"
              },
              "additional_properties": {
                "id": "dff80195-f6af-4c6b-bee0-204367782db6",
                "numbered": false,
                "edge": false,
                "service_type": "routetable",
                "generic_type": "Services::Network::RouteTable",
                "type": "Services::Network::RouteTable::AWS",
                "name": "RTB-V20-$$function##",
                "drawable": true,
                "draggable": true,
                "interfaces": [
                  {
                    "id": "41fccaf3-ab91-47da-9358-3a86bbaab300",
                    "name": "route_table",
                    "depends": false,
                    "connections": null,
                    "interface_type": "Protocols::RouteTable"
                  },
                  {
                    "id": "a392080b-c522-4a44-b496-c84cbe5b6747",
                    "name": "prat",
                    "depends": true,
                    "connections": [
                      {
                        "id": "f724259b-0400-48b9-8cc9-8fba0216bef1",
                        "interface_id": "a392080b-c522-4a44-b496-c84cbe5b6747",
                        "remote_interface_id": "cbb835cb-991c-4b69-a081-195c597025bf",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::Vpc"
                  }
                ],
                "vpc_id": "16ef4f23-56e2-4f45-a2e3-f20e907bbfcf",
                "primary_key": "",
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
                "depends": null,
                "parent_id": "9d27f097-3fe8-4907-b8aa-305ba973b6da",
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
                        "origin": "CreateRouteTable",
                        "state": "active"
                      },
                      {
                        "destinationCidrBlock": "0.0.0.0/0",
                        "connected_service": "",
                        "connected_service_name": "EC2-$$servername-$$function##-V20",
                        "connected_service_id": "7bc345f2-d337-46ff-843f-55d54d3f91fc"
                      },
                      {
                        "destinationCidrBlock": "0.0.32.0/0",
                        "connected_service": "",
                        "connected_service_name": "EC2-$$servername-$$function##-V20",
                        "connected_service_id": "4e621aa6-6a4d-4676-ae41-2b2bd5bb3647"
                      },
                      {
                        "destinationCidrBlock": "0.0.0.0/0",
                        "connected_service": "internet_gateway",
                        "connected_service_name": "internet_gateway",
                        "connected_service_id": "e7eae976-6191-43dc-ae91-3d60a5ac48db"
                      }
                    ],
                    "form_options": {
                      "type": "text",
                      "readonly": true
                    },
                    "title": "Routes"
                  }
                ]
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
                      "origin": "CreateRouteTable",
                      "state": "active"
                    },
                    {
                      "destinationCidrBlock": "0.0.0.0/0",
                      "connected_service": "",
                      "connected_service_name": "EC2-$$servername-$$function##-V20",
                      "connected_service_id": "7bc345f2-d337-46ff-843f-55d54d3f91fc"
                    },
                    {
                      "destinationCidrBlock": "0.0.32.0/0",
                      "connected_service": "",
                      "connected_service_name": "EC2-$$servername-$$function##-V20",
                      "connected_service_id": "4e621aa6-6a4d-4676-ae41-2b2bd5bb3647"
                    },
                    {
                      "destinationCidrBlock": "0.0.0.0/0",
                      "connected_service": "internet_gateway",
                      "connected_service_name": "internet_gateway",
                      "connected_service_id": "e7eae976-6191-43dc-ae91-3d60a5ac48db"
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
                  "id": "41fccaf3-ab91-47da-9358-3a86bbaab300",
                  "name": "route_table",
                  "interface_type": "Protocols::RouteTable",
                  "depends": false,
                  "connections": []
                },
                {
                  "id": "a392080b-c522-4a44-b496-c84cbe5b6747",
                  "name": "prat",
                  "interface_type": "Protocols::Vpc",
                  "depends": true,
                  "connections": [
                    {
                      "id": "f724259b-0400-48b9-8cc9-8fba0216bef1",
                      "interface_id": "a392080b-c522-4a44-b496-c84cbe5b6747",
                      "remote_interface_id": "cbb835cb-991c-4b69-a081-195c597025bf",
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
              "id": "e7eae976-6191-43dc-ae91-3d60a5ac48db",
              "name": "internet_gateway",
              "type": "Services::Network::InternetGateway::AWS",
              "geometry": {
                "x": "-28",
                "y": "20",
                "width": "56",
                "height": "56"
              },
              "additional_properties": {
                "id": "e7eae976-6191-43dc-ae91-3d60a5ac48db",
                "edge": false,
                "service_type": "InternetGateway",
                "generic_type": "Services::Network::InternetGateway",
                "type": "Services::Network::InternetGateway::AWS",
                "name": "internet_gateway",
                "drawable": true,
                "draggable": false,
                "interfaces": [
                  {
                    "id": "4e8eba84-08d9-4817-a93b-73eaea8a77b0",
                    "name": "internet_gateway",
                    "depends": false,
                    "connections": null,
                    "interface_type": "Protocols::InternetGateway"
                  },
                  {
                    "id": "4799fd16-e283-4b7b-bead-7fa6bac56cb5",
                    "name": "prat",
                    "depends": true,
                    "connections": [
                      {
                        "id": "70b92458-2f08-4c0c-963c-09d4298808ff",
                        "interface_id": "4799fd16-e283-4b7b-bead-7fa6bac56cb5",
                        "remote_interface_id": "cbb835cb-991c-4b69-a081-195c597025bf",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::Vpc"
                  }
                ],
                "vpc_id": "16ef4f23-56e2-4f45-a2e3-f20e907bbfcf",
                "primary_key": "",
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
                "depends": null,
                "parent_id": "9d27f097-3fe8-4907-b8aa-305ba973b6da",
                "properties": [
                  {
                    "name": "internet_gateway_id",
                    "value": "igw-f3d1f497",
                    "form_options": {
                      "type": "hidden"
                    },
                    "title": "Internet Gateway Id"
                  }
                ]
              },
              "properties": [
                {
                  "name": "internet_gateway_id",
                  "value": "igw-f3d1f497",
                  "form_options": {
                    "type": "hidden"
                  },
                  "title": "Internet Gateway Id"
                }
              ],
              "interfaces": [
                {
                  "id": "4e8eba84-08d9-4817-a93b-73eaea8a77b0",
                  "name": "internet_gateway",
                  "interface_type": "Protocols::InternetGateway",
                  "depends": false,
                  "connections": []
                },
                {
                  "id": "4799fd16-e283-4b7b-bead-7fa6bac56cb5",
                  "name": "prat",
                  "interface_type": "Protocols::Vpc",
                  "depends": true,
                  "connections": [
                    {
                      "id": "70b92458-2f08-4c0c-963c-09d4298808ff",
                      "interface_id": "4799fd16-e283-4b7b-bead-7fa6bac56cb5",
                      "remote_interface_id": "cbb835cb-991c-4b69-a081-195c597025bf",
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
              "id": "19ea9572-c5ed-49f9-aff1-8eded2a2ef63",
              "name": "SN-V20-$$servername-##",
              "type": "Services::Network::Subnet::AWS",
              "geometry": {
                "x": "40",
                "y": "635.6",
                "width": "180",
                "height": "180"
              },
              "container": true,
              "additional_properties": {
                "id": "19ea9572-c5ed-49f9-aff1-8eded2a2ef63",
                "numbered": true,
                "edge": false,
                "service_type": "subnet",
                "generic_type": "Services::Network::Subnet",
                "type": "Services::Network::Subnet::AWS",
                "name": "SN-V20-$$servername-##",
                "container": true,
                "drawable": true,
                "draggable": true,
                "interfaces": [
                  {
                    "id": "ca42bb6b-4723-45b5-bcf6-544b1779edca",
                    "name": "SN-V20-$$servername-##",
                    "depends": false,
                    "connections": null,
                    "interface_type": "Protocols::Subnet"
                  },
                  {
                    "id": "1b7b8c1e-00f7-4f3b-8b48-3e14aefd1102",
                    "name": "availability_zone1",
                    "depends": true,
                    "connections": [
                      {
                        "id": "66563893-d452-44af-ad53-c72b182c1884",
                        "interface_id": "1b7b8c1e-00f7-4f3b-8b48-3e14aefd1102",
                        "remote_interface_id": "f1629909-7efa-432b-9844-65af8bfc84c2",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::AvailabilityZone"
                  },
                  {
                    "id": "017b6166-11ee-4c0d-9f92-2a35fa6cb7ff",
                    "name": "prat",
                    "depends": true,
                    "connections": [
                      {
                        "id": "734eaa9b-c074-4d29-bad1-c02f3ab8b5fb",
                        "interface_id": "017b6166-11ee-4c0d-9f92-2a35fa6cb7ff",
                        "remote_interface_id": "cbb835cb-991c-4b69-a081-195c597025bf",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::Vpc"
                  }
                ],
                "vpc_id": "16ef4f23-56e2-4f45-a2e3-f20e907bbfcf",
                "primary_key": "",
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
                ],
                "parent_id": "453b1dd5-dd10-42ff-bb88-acae525e38a9",
                "properties": [
                  {
                    "name": "cidr_block",
                    "value": "10.0.32.0/19",
                    "form_options": {
                      "type": "text"
                    },
                    "title": "CIDR Block"
                  }
                ],
                "lastAssignedIP": "10.0.32.0",
                "existing_subnet": "837907f0-6913-4a86-9cc1-5b7e79cc6c37"
              },
              "properties": [
                {
                  "name": "cidr_block",
                  "value": "10.0.32.0/19",
                  "form_options": {
                    "type": "text"
                  },
                  "title": "CIDR Block"
                }
              ],
              "interfaces": [
                {
                  "id": "ca42bb6b-4723-45b5-bcf6-544b1779edca",
                  "name": "SN-V20-$$servername-##",
                  "interface_type": "Protocols::Subnet",
                  "depends": false,
                  "connections": []
                },
                {
                  "id": "1b7b8c1e-00f7-4f3b-8b48-3e14aefd1102",
                  "name": "availability_zone1",
                  "interface_type": "Protocols::AvailabilityZone",
                  "depends": true,
                  "connections": [
                    {
                      "id": "66563893-d452-44af-ad53-c72b182c1884",
                      "interface_id": "1b7b8c1e-00f7-4f3b-8b48-3e14aefd1102",
                      "remote_interface_id": "f1629909-7efa-432b-9844-65af8bfc84c2",
                      "internal": false
                    }
                  ]
                },
                {
                  "id": "017b6166-11ee-4c0d-9f92-2a35fa6cb7ff",
                  "name": "prat",
                  "interface_type": "Protocols::Vpc",
                  "depends": true,
                  "connections": [
                    {
                      "id": "734eaa9b-c074-4d29-bad1-c02f3ab8b5fb",
                      "interface_id": "017b6166-11ee-4c0d-9f92-2a35fa6cb7ff",
                      "remote_interface_id": "cbb835cb-991c-4b69-a081-195c597025bf",
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
              "id": "c6fe71d4-e3f1-4de7-ad85-b54a2616a033",
              "name": "SN-V20-$$servername-##",
              "type": "Services::Network::Subnet::AWS",
              "geometry": {
                "x": "35.6",
                "y": "30",
                "width": "180",
                "height": "600"
              },
              "container": true,
              "additional_properties": {
                "id": "c6fe71d4-e3f1-4de7-ad85-b54a2616a033",
                "numbered": false,
                "edge": false,
                "service_type": "subnet",
                "generic_type": "Services::Network::Subnet",
                "type": "Services::Network::Subnet::AWS",
                "name": "SN-V20-$$servername-##",
                "container": true,
                "drawable": true,
                "draggable": true,
                "interfaces": [
                  {
                    "id": "06b21844-962a-48a0-b1d5-4eac6cac5055",
                    "name": "subnet",
                    "depends": false,
                    "connections": null,
                    "interface_type": "Protocols::Subnet"
                  },
                  {
                    "id": "1d54eacc-3594-4650-98e5-b922ec64c0a8",
                    "name": "availability_zone1",
                    "depends": true,
                    "connections": [
                      {
                        "id": "72c0d923-00a4-4928-a63c-b7747aa97cea",
                        "interface_id": "1d54eacc-3594-4650-98e5-b922ec64c0a8",
                        "remote_interface_id": "9067b337-c820-4315-8099-413cddc3d083",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::AvailabilityZone"
                  },
                  {
                    "id": "fec54ad5-eb10-4afc-97cd-bda965e53d21",
                    "name": "prat",
                    "depends": true,
                    "connections": [
                      {
                        "id": "6d7976d2-eb56-477e-8196-ec4a29901521",
                        "interface_id": "fec54ad5-eb10-4afc-97cd-bda965e53d21",
                        "remote_interface_id": "cbb835cb-991c-4b69-a081-195c597025bf",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::Vpc"
                  }
                ],
                "vpc_id": "16ef4f23-56e2-4f45-a2e3-f20e907bbfcf",
                "primary_key": "",
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
                ],
                "parent_id": "f594d0bb-df08-41e8-8070-8c21347b2bd9",
                "properties": [
                  {
                    "name": "cidr_block",
                    "value": "10.0.160.0/19",
                    "form_options": {
                      "type": "text"
                    },
                    "title": "CIDR Block"
                  }
                ],
                "lastAssignedIP": "10.0.160.0",
                "existing_subnet": ""
              },
              "properties": [
                {
                  "name": "cidr_block",
                  "value": "10.0.160.0/19",
                  "form_options": {
                    "type": "text"
                  },
                  "title": "CIDR Block"
                }
              ],
              "interfaces": [
                {
                  "id": "06b21844-962a-48a0-b1d5-4eac6cac5055",
                  "name": "subnet",
                  "interface_type": "Protocols::Subnet",
                  "depends": false,
                  "connections": []
                },
                {
                  "id": "1d54eacc-3594-4650-98e5-b922ec64c0a8",
                  "name": "availability_zone1",
                  "interface_type": "Protocols::AvailabilityZone",
                  "depends": true,
                  "connections": [
                    {
                      "id": "72c0d923-00a4-4928-a63c-b7747aa97cea",
                      "interface_id": "1d54eacc-3594-4650-98e5-b922ec64c0a8",
                      "remote_interface_id": "9067b337-c820-4315-8099-413cddc3d083",
                      "internal": false
                    }
                  ]
                },
                {
                  "id": "fec54ad5-eb10-4afc-97cd-bda965e53d21",
                  "name": "prat",
                  "interface_type": "Protocols::Vpc",
                  "depends": true,
                  "connections": [
                    {
                      "id": "6d7976d2-eb56-477e-8196-ec4a29901521",
                      "interface_id": "fec54ad5-eb10-4afc-97cd-bda965e53d21",
                      "remote_interface_id": "cbb835cb-991c-4b69-a081-195c597025bf",
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
              "id": "f167f871-edca-47cc-b2c6-9e168f152c77",
              "name": "SN-V20-$$servername-##",
              "type": "Services::Network::Subnet::AWS",
              "geometry": {
                "x": "50",
                "y": "140.96",
                "width": "180",
                "height": "180"
              },
              "container": true,
              "additional_properties": {
                "id": "f167f871-edca-47cc-b2c6-9e168f152c77",
                "numbered": false,
                "edge": false,
                "service_type": "subnet",
                "generic_type": "Services::Network::Subnet",
                "type": "Services::Network::Subnet::AWS",
                "name": "SN-V20-$$servername-##",
                "container": true,
                "drawable": true,
                "draggable": true,
                "interfaces": [
                  {
                    "id": "00a5f387-643e-46bb-949c-3062b5bfebec",
                    "name": "subnet",
                    "depends": false,
                    "connections": null,
                    "interface_type": "Protocols::Subnet"
                  },
                  {
                    "id": "245225c7-b200-4538-992b-8d59182e3270",
                    "name": "availability_zone1",
                    "depends": true,
                    "connections": [
                      {
                        "id": "60f17700-b2a3-4f65-ac0e-9cbceab6f289",
                        "interface_id": "245225c7-b200-4538-992b-8d59182e3270",
                        "remote_interface_id": "e91c2e00-47ec-44f9-8323-eb716822af0a",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::AvailabilityZone"
                  },
                  {
                    "id": "b882600e-906e-4567-8336-96ad778677d5",
                    "name": "prat",
                    "depends": true,
                    "connections": [
                      {
                        "id": "981cd93d-96fc-4c2a-a6de-4da1f1ffcf9b",
                        "interface_id": "b882600e-906e-4567-8336-96ad778677d5",
                        "remote_interface_id": "cbb835cb-991c-4b69-a081-195c597025bf",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::Vpc"
                  }
                ],
                "vpc_id": "16ef4f23-56e2-4f45-a2e3-f20e907bbfcf",
                "primary_key": "",
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
                ],
                "parent_id": "32dcb870-0d4f-46cc-86a8-95cbb6d54cc4",
                "properties": [
                  {
                    "name": "cidr_block",
                    "value": "10.0.192.0/19",
                    "form_options": {
                      "type": "text"
                    },
                    "title": "CIDR Block"
                  }
                ],
                "lastAssignedIP": "10.0.192.0",
                "existing_subnet": ""
              },
              "properties": [
                {
                  "name": "cidr_block",
                  "value": "10.0.192.0/19",
                  "form_options": {
                    "type": "text"
                  },
                  "title": "CIDR Block"
                }
              ],
              "interfaces": [
                {
                  "id": "00a5f387-643e-46bb-949c-3062b5bfebec",
                  "name": "subnet",
                  "interface_type": "Protocols::Subnet",
                  "depends": false,
                  "connections": []
                },
                {
                  "id": "245225c7-b200-4538-992b-8d59182e3270",
                  "name": "availability_zone1",
                  "interface_type": "Protocols::AvailabilityZone",
                  "depends": true,
                  "connections": [
                    {
                      "id": "60f17700-b2a3-4f65-ac0e-9cbceab6f289",
                      "interface_id": "245225c7-b200-4538-992b-8d59182e3270",
                      "remote_interface_id": "e91c2e00-47ec-44f9-8323-eb716822af0a",
                      "internal": false
                    }
                  ]
                },
                {
                  "id": "b882600e-906e-4567-8336-96ad778677d5",
                  "name": "prat",
                  "interface_type": "Protocols::Vpc",
                  "depends": true,
                  "connections": [
                    {
                      "id": "981cd93d-96fc-4c2a-a6de-4da1f1ffcf9b",
                      "interface_id": "b882600e-906e-4567-8336-96ad778677d5",
                      "remote_interface_id": "cbb835cb-991c-4b69-a081-195c597025bf",
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
              "id": "0af16444-1472-4db3-9a53-8c4707e4f2b6",
              "name": "SN-V20-$$servername-##",
              "type": "Services::Network::Subnet::AWS",
              "geometry": {
                "x": "40",
                "y": "435.6",
                "width": "180",
                "height": "180"
              },
              "container": true,
              "additional_properties": {
                "id": "0af16444-1472-4db3-9a53-8c4707e4f2b6",
                "numbered": false,
                "edge": false,
                "service_type": "subnet",
                "generic_type": "Services::Network::Subnet",
                "type": "Services::Network::Subnet::AWS",
                "name": "SN-V20-$$servername-##",
                "container": true,
                "drawable": true,
                "draggable": true,
                "interfaces": [
                  {
                    "id": "b2c454b8-ea36-48d2-9b11-140cc3b1a4a4",
                    "name": "subnet",
                    "depends": false,
                    "connections": null,
                    "interface_type": "Protocols::Subnet"
                  },
                  {
                    "id": "65c29866-618f-47bc-818d-4295072c4253",
                    "name": "availability_zone1",
                    "depends": true,
                    "connections": [
                      {
                        "id": "d1863660-7b81-48df-a351-6f7003bbf39f",
                        "interface_id": "65c29866-618f-47bc-818d-4295072c4253",
                        "remote_interface_id": "f1629909-7efa-432b-9844-65af8bfc84c2",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::AvailabilityZone"
                  },
                  {
                    "id": "f1a0383d-5271-4216-9d06-713302784ec7",
                    "name": "prat",
                    "depends": true,
                    "connections": [
                      {
                        "id": "cab2441c-a5f1-43af-8231-e50f8e43cc3f",
                        "interface_id": "f1a0383d-5271-4216-9d06-713302784ec7",
                        "remote_interface_id": "cbb835cb-991c-4b69-a081-195c597025bf",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::Vpc"
                  }
                ],
                "vpc_id": "16ef4f23-56e2-4f45-a2e3-f20e907bbfcf",
                "primary_key": "",
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
                ],
                "parent_id": "453b1dd5-dd10-42ff-bb88-acae525e38a9",
                "properties": [
                  {
                    "name": "cidr_block",
                    "value": "10.0.224.0/19",
                    "form_options": {
                      "type": "text"
                    },
                    "title": "CIDR Block"
                  }
                ],
                "lastAssignedIP": "10.0.224.0",
                "existing_subnet": ""
              },
              "properties": [
                {
                  "name": "cidr_block",
                  "value": "10.0.224.0/19",
                  "form_options": {
                    "type": "text"
                  },
                  "title": "CIDR Block"
                }
              ],
              "interfaces": [
                {
                  "id": "f1a0383d-5271-4216-9d06-713302784ec7",
                  "name": "prat",
                  "interface_type": "Protocols::Vpc",
                  "depends": true,
                  "connections": [
                    {
                      "id": "cab2441c-a5f1-43af-8231-e50f8e43cc3f",
                      "interface_id": "f1a0383d-5271-4216-9d06-713302784ec7",
                      "remote_interface_id": "cbb835cb-991c-4b69-a081-195c597025bf",
                      "internal": false
                    }
                  ]
                },
                {
                  "id": "b2c454b8-ea36-48d2-9b11-140cc3b1a4a4",
                  "name": "subnet",
                  "interface_type": "Protocols::Subnet",
                  "depends": false,
                  "connections": []
                },
                {
                  "id": "65c29866-618f-47bc-818d-4295072c4253",
                  "name": "availability_zone1",
                  "interface_type": "Protocols::AvailabilityZone",
                  "depends": true,
                  "connections": [
                    {
                      "id": "d1863660-7b81-48df-a351-6f7003bbf39f",
                      "interface_id": "65c29866-618f-47bc-818d-4295072c4253",
                      "remote_interface_id": "f1629909-7efa-432b-9844-65af8bfc84c2",
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
              "id": "0dbde654-1252-49b0-978d-f0fab5713cc5",
              "name": "SN-V20-$$servername-##",
              "type": "Services::Network::Subnet::AWS",
              "geometry": {
                "x": "40",
                "y": "45.6",
                "width": "180",
                "height": "180"
              },
              "container": true,
              "additional_properties": {
                "id": "0dbde654-1252-49b0-978d-f0fab5713cc5",
                "numbered": false,
                "edge": false,
                "service_type": "subnet",
                "generic_type": "Services::Network::Subnet",
                "type": "Services::Network::Subnet::AWS",
                "name": "SN-V20-$$servername-##",
                "container": true,
                "drawable": true,
                "draggable": true,
                "interfaces": [
                  {
                    "id": "95af5f53-00a9-4a86-9e69-fba5bf0336f7",
                    "name": "subnet",
                    "depends": false,
                    "connections": null,
                    "interface_type": "Protocols::Subnet"
                  },
                  {
                    "id": "b8dcac1c-d2e1-492a-9ec1-4457b1a519bb",
                    "name": "availability_zone1",
                    "depends": true,
                    "connections": [
                      {
                        "id": "4fad0025-5997-42f5-bbb0-268f89fcd390",
                        "interface_id": "b8dcac1c-d2e1-492a-9ec1-4457b1a519bb",
                        "remote_interface_id": "f1629909-7efa-432b-9844-65af8bfc84c2",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::AvailabilityZone"
                  },
                  {
                    "id": "877c85a1-881a-4ca0-b06e-7289f66d2284",
                    "name": "prat",
                    "depends": true,
                    "connections": [
                      {
                        "id": "f152d011-7c00-4b11-b0b2-eefe90025446",
                        "interface_id": "877c85a1-881a-4ca0-b06e-7289f66d2284",
                        "remote_interface_id": "cbb835cb-991c-4b69-a081-195c597025bf",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::Vpc"
                  }
                ],
                "vpc_id": "16ef4f23-56e2-4f45-a2e3-f20e907bbfcf",
                "primary_key": "",
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
                ],
                "parent_id": "453b1dd5-dd10-42ff-bb88-acae525e38a9",
                "properties": [
                  {
                    "name": "cidr_block",
                    "value": "10.0.96.0/19",
                    "form_options": {
                      "type": "text"
                    },
                    "title": "CIDR Block"
                  }
                ],
                "lastAssignedIP": "10.0.96.0",
                "existing_subnet": ""
              },
              "properties": [
                {
                  "name": "cidr_block",
                  "value": "10.0.96.0/19",
                  "form_options": {
                    "type": "text"
                  },
                  "title": "CIDR Block"
                }
              ],
              "interfaces": [
                {
                  "id": "95af5f53-00a9-4a86-9e69-fba5bf0336f7",
                  "name": "subnet",
                  "interface_type": "Protocols::Subnet",
                  "depends": false,
                  "connections": []
                },
                {
                  "id": "b8dcac1c-d2e1-492a-9ec1-4457b1a519bb",
                  "name": "availability_zone1",
                  "interface_type": "Protocols::AvailabilityZone",
                  "depends": true,
                  "connections": [
                    {
                      "id": "4fad0025-5997-42f5-bbb0-268f89fcd390",
                      "interface_id": "b8dcac1c-d2e1-492a-9ec1-4457b1a519bb",
                      "remote_interface_id": "f1629909-7efa-432b-9844-65af8bfc84c2",
                      "internal": false
                    }
                  ]
                },
                {
                  "id": "877c85a1-881a-4ca0-b06e-7289f66d2284",
                  "name": "prat",
                  "interface_type": "Protocols::Vpc",
                  "depends": true,
                  "connections": [
                    {
                      "id": "f152d011-7c00-4b11-b0b2-eefe90025446",
                      "interface_id": "877c85a1-881a-4ca0-b06e-7289f66d2284",
                      "remote_interface_id": "cbb835cb-991c-4b69-a081-195c597025bf",
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
              "id": "4c6b2b1f-70b2-42ff-a6ed-c80ea606e222",
              "name": "SN-V20-$$servername-##",
              "type": "Services::Network::Subnet::AWS",
              "geometry": {
                "x": "40",
                "y": "235.6",
                "width": "180",
                "height": "180"
              },
              "container": true,
              "additional_properties": {
                "id": "4c6b2b1f-70b2-42ff-a6ed-c80ea606e222",
                "numbered": false,
                "edge": false,
                "service_type": "subnet",
                "generic_type": "Services::Network::Subnet",
                "type": "Services::Network::Subnet::AWS",
                "name": "SN-V20-$$servername-##",
                "container": true,
                "drawable": true,
                "draggable": true,
                "interfaces": [
                  {
                    "id": "8b5056c1-a861-4c47-b829-3f87fe76e33b",
                    "name": "subnet",
                    "depends": false,
                    "connections": null,
                    "interface_type": "Protocols::Subnet"
                  },
                  {
                    "id": "aba31888-b825-4ec1-aba7-f54fafcbce3e",
                    "name": "availability_zone1",
                    "depends": true,
                    "connections": [
                      {
                        "id": "2b22eba7-d6f6-4d35-a879-8876ef1567cc",
                        "interface_id": "aba31888-b825-4ec1-aba7-f54fafcbce3e",
                        "remote_interface_id": "f1629909-7efa-432b-9844-65af8bfc84c2",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::AvailabilityZone"
                  },
                  {
                    "id": "dc5e5275-1943-4a3c-91b5-cedafb7379e3",
                    "name": "prat",
                    "depends": true,
                    "connections": [
                      {
                        "id": "88435e2c-6978-4859-a480-872861b0d0a6",
                        "interface_id": "dc5e5275-1943-4a3c-91b5-cedafb7379e3",
                        "remote_interface_id": "cbb835cb-991c-4b69-a081-195c597025bf",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::Vpc"
                  }
                ],
                "vpc_id": "16ef4f23-56e2-4f45-a2e3-f20e907bbfcf",
                "primary_key": "",
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
                ],
                "parent_id": "453b1dd5-dd10-42ff-bb88-acae525e38a9",
                "properties": [
                  {
                    "name": "cidr_block",
                    "value": "10.0.128.0/19",
                    "form_options": {
                      "type": "text"
                    },
                    "title": "CIDR Block"
                  }
                ],
                "lastAssignedIP": "10.0.128.0",
                "existing_subnet": ""
              },
              "properties": [
                {
                  "name": "cidr_block",
                  "value": "10.0.128.0/19",
                  "form_options": {
                    "type": "text"
                  },
                  "title": "CIDR Block"
                }
              ],
              "interfaces": [
                {
                  "id": "8b5056c1-a861-4c47-b829-3f87fe76e33b",
                  "name": "subnet",
                  "interface_type": "Protocols::Subnet",
                  "depends": false,
                  "connections": []
                },
                {
                  "id": "aba31888-b825-4ec1-aba7-f54fafcbce3e",
                  "name": "availability_zone1",
                  "interface_type": "Protocols::AvailabilityZone",
                  "depends": true,
                  "connections": [
                    {
                      "id": "2b22eba7-d6f6-4d35-a879-8876ef1567cc",
                      "interface_id": "aba31888-b825-4ec1-aba7-f54fafcbce3e",
                      "remote_interface_id": "f1629909-7efa-432b-9844-65af8bfc84c2",
                      "internal": false
                    }
                  ]
                },
                {
                  "id": "dc5e5275-1943-4a3c-91b5-cedafb7379e3",
                  "name": "prat",
                  "interface_type": "Protocols::Vpc",
                  "depends": true,
                  "connections": [
                    {
                      "id": "88435e2c-6978-4859-a480-872861b0d0a6",
                      "interface_id": "dc5e5275-1943-4a3c-91b5-cedafb7379e3",
                      "remote_interface_id": "cbb835cb-991c-4b69-a081-195c597025bf",
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
              "id": "ba078cce-e361-4c79-ae32-fa4c4bea2537",
              "name": "EC2-$$servername-$$function##-V20",
              "type": "Services::Compute::Server::AWS",
              "geometry": {
                "x": "20",
                "y": "40",
                "width": "56",
                "height": "56"
              },
              "additional_properties": {
                "id": "ba078cce-e361-4c79-ae32-fa4c4bea2537",
                "numbered": false,
                "edge": false,
                "service_type": "server",
                "generic_type": "Services::Compute::Server",
                "type": "Services::Compute::Server::AWS",
                "name": "EC2-$$servername-$$function##-V20",
                "drawable": true,
                "draggable": true,
                "interfaces": [
                  {
                    "id": "3fafae4a-6c7e-4f39-a879-dd27b9c09a3d",
                    "name": "default",
                    "depends": true,
                    "connections": [
                      {
                        "id": "c3d12f1c-3059-4d81-b996-40d171c392d4",
                        "interface_id": "3fafae4a-6c7e-4f39-a879-dd27b9c09a3d",
                        "remote_interface_id": "94c76455-6f91-4128-8db1-d90b323cf891",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::SecurityGroup"
                  },
                  {
                    "id": "39ca6d55-cc48-402c-a843-d225af4dee1e",
                    "name": "win01",
                    "depends": false,
                    "connections": null,
                    "interface_type": "Protocols::Server"
                  },
                  {
                    "id": "227c5d8b-29be-40fa-90a1-5ee4b160acf2",
                    "name": "SN-V20-$$servername-##",
                    "depends": true,
                    "connections": [
                      {
                        "id": "781f6f5d-1469-4ce5-acab-432650e6e46d",
                        "interface_id": "227c5d8b-29be-40fa-90a1-5ee4b160acf2",
                        "remote_interface_id": "b2c454b8-ea36-48d2-9b11-140cc3b1a4a4",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::Subnet"
                  },
                  {
                    "id": "5bf19947-229d-4974-bd81-e57979e55434",
                    "name": "prat",
                    "depends": true,
                    "connections": [
                      {
                        "id": "9581e94c-fa14-44dc-b981-55feb34cfdcd",
                        "interface_id": "5bf19947-229d-4974-bd81-e57979e55434",
                        "remote_interface_id": "cbb835cb-991c-4b69-a081-195c597025bf",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::Vpc"
                  },
                  {
                    "id": "5afd8c59-b741-4e66-be08-35d628df3fe6",
                    "name": "VOL-$$serviceowner-$$function##-V20-",
                    "depends": true,
                    "connections": [
                      {
                        "id": "2bf55826-81da-4c7c-89da-3a3231259827",
                        "interface_id": "5afd8c59-b741-4e66-be08-35d628df3fe6",
                        "remote_interface_id": "f90e3fbc-4df8-4b1f-a541-bb0a92d3a3ba",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::Disk"
                  },
                  {
                    "id": "ad86e505-5902-4ea3-a603-ecf8f20730dd",
                    "name": "ELBV20MAZ##",
                    "depends": true,
                    "connections": [
                      {
                        "id": "baf2afec-979c-4107-a636-a2d16c46d1e5",
                        "interface_id": "ad86e505-5902-4ea3-a603-ecf8f20730dd",
                        "remote_interface_id": "6b6d3835-03e6-49f4-b43e-0543381e3bf0",
                        "type": "explicit"
                      }
                    ],
                    "interface_type": "Protocols::LoadBalancer"
                  }
                ],
                "vpc_id": "16ef4f23-56e2-4f45-a2e3-f20e907bbfcf",
                "primary_key": "",
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
                ],
                "parent_id": "0af16444-1472-4db3-9a53-8c4707e4f2b6",
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
                    "value": "ami-2e297d39",
                    "form_options": {
                      "type": "text",
                      "readonly": "readonly"
                    },
                    "title": "AMI"
                  },
                  {
                    "name": "image_config_id",
                    "value": "0f362b87-91d3-45d2-b1c8-cfb23ce9a402",
                    "form_options": {
                      "type": "select",
                      "options": {
                        "0f362b87-91d3-45d2-b1c8-cfb23ce9a402": "q1"
                      },
                      "flavor_ids": {
                        "0f362b87-91d3-45d2-b1c8-cfb23ce9a402": []
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
                    "value": "t2.nano",
                    "form_options": {
                      "type": "select",
                      "options": [
                        "t2.nano",
                        "t1.micro",
                        "t2.micro",
                        "t2.small",
                        "t2.medium",
                        "t2.large",
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
                    "value": "windows",
                    "form_options": {
                      "type": "hidden",
                      "readonly": "readonly"
                    },
                    "title": "Platform"
                  },
                  {
                    "name": "associate_public_ip",
                    "value": false,
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
                    "value": false,
                    "form_options": {
                      "type": "checkbox",
                      "required": false
                    },
                    "title": "EBS Optimized"
                  },
                  {
                    "name": "instance_monitoring",
                    "value": false,
                    "form_options": {
                      "type": "checkbox",
                      "required": false
                    },
                    "title": "Instance Monitoring"
                  },
                  {
                    "name": "key_name",
                    "value": "",
                    "form_options": {
                      "type": "select",
                      "options": [
                        "marketplace-test",
                        "marketplace-test",
                        "marketplace-test"
                      ],
                      "data": [
                        "marketplace-test",
                        "marketplace-test",
                        "marketplace-test"
                      ],
                      "keepfirstblank": true,
                      "firstblanktext": "Proceed without a Key Pair"
                    },
                    "title": "Select Key Pair"
                  },
                  {
                    "name": "original_block_device_mappings",
                    "value": [
                      {
                        "Ebs.VolumeSize": "30",
                        "Ebs.VolumeType": "gp2",
                        "Ebs.Iops": "90",
                        "Ebs.DeleteOnTermination": true,
                        "DevicePath": "/dev/sda1",
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
                    "value": "",
                    "form_options": {
                      "type": "select",
                      "options": [
                        ""
                      ],
                      "keepfirstblank": true,
                      "firstblanktext": "None",
                      "required": false
                    },
                    "title": "IAM Role"
                  }
                ]
              },
              "display_name": "win01",
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
                  "value": "ami-2e297d39",
                  "form_options": {
                    "type": "text",
                    "readonly": "readonly"
                  },
                  "title": "AMI"
                },
                {
                  "name": "image_config_id",
                  "value": "0f362b87-91d3-45d2-b1c8-cfb23ce9a402",
                  "form_options": {
                    "type": "select",
                    "options": {
                      "0f362b87-91d3-45d2-b1c8-cfb23ce9a402": "q1"
                    },
                    "flavor_ids": {
                      "0f362b87-91d3-45d2-b1c8-cfb23ce9a402": []
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
                  "value": "t2.nano",
                  "form_options": {
                    "type": "select",
                    "options": [
                      "t2.nano",
                      "t1.micro",
                      "t2.micro",
                      "t2.small",
                      "t2.medium",
                      "t2.large",
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
                  "value": "windows",
                  "form_options": {
                    "type": "hidden",
                    "readonly": "readonly"
                  },
                  "title": "Platform"
                },
                {
                  "name": "associate_public_ip",
                  "value": false,
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
                  "value": false,
                  "form_options": {
                    "type": "checkbox",
                    "required": false
                  },
                  "title": "EBS Optimized"
                },
                {
                  "name": "instance_monitoring",
                  "value": false,
                  "form_options": {
                    "type": "checkbox",
                    "required": false
                  },
                  "title": "Instance Monitoring"
                },
                {
                  "name": "key_name",
                  "value": "",
                  "form_options": {
                    "type": "select",
                    "options": [
                      "marketplace-test",
                      "marketplace-test",
                      "marketplace-test"
                    ],
                    "data": [
                      "marketplace-test",
                      "marketplace-test",
                      "marketplace-test"
                    ],
                    "keepfirstblank": true,
                    "firstblanktext": "Proceed without a Key Pair"
                  },
                  "title": "Select Key Pair"
                },
                {
                  "name": "original_block_device_mappings",
                  "value": [
                    {
                      "Ebs.VolumeSize": "30",
                      "Ebs.VolumeType": "gp2",
                      "Ebs.Iops": "90",
                      "Ebs.DeleteOnTermination": true,
                      "DevicePath": "/dev/sda1",
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
                  "value": "",
                  "form_options": {
                    "type": "select",
                    "options": [
                      ""
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
                  "id": "3fafae4a-6c7e-4f39-a879-dd27b9c09a3d",
                  "name": "default",
                  "interface_type": "Protocols::SecurityGroup",
                  "depends": true,
                  "connections": [
                    {
                      "id": "c3d12f1c-3059-4d81-b996-40d171c392d4",
                      "interface_id": "3fafae4a-6c7e-4f39-a879-dd27b9c09a3d",
                      "remote_interface_id": "94c76455-6f91-4128-8db1-d90b323cf891",
                      "internal": false
                    }
                  ]
                },
                {
                  "id": "39ca6d55-cc48-402c-a843-d225af4dee1e",
                  "name": "win01",
                  "interface_type": "Protocols::Server",
                  "depends": false,
                  "connections": []
                },
                {
                  "id": "227c5d8b-29be-40fa-90a1-5ee4b160acf2",
                  "name": "SN-V20-$$servername-##",
                  "interface_type": "Protocols::Subnet",
                  "depends": true,
                  "connections": [
                    {
                      "id": "781f6f5d-1469-4ce5-acab-432650e6e46d",
                      "interface_id": "227c5d8b-29be-40fa-90a1-5ee4b160acf2",
                      "remote_interface_id": "b2c454b8-ea36-48d2-9b11-140cc3b1a4a4",
                      "internal": false
                    }
                  ]
                },
                {
                  "id": "5bf19947-229d-4974-bd81-e57979e55434",
                  "name": "prat",
                  "interface_type": "Protocols::Vpc",
                  "depends": true,
                  "connections": [
                    {
                      "id": "9581e94c-fa14-44dc-b981-55feb34cfdcd",
                      "interface_id": "5bf19947-229d-4974-bd81-e57979e55434",
                      "remote_interface_id": "cbb835cb-991c-4b69-a081-195c597025bf",
                      "internal": false
                    }
                  ]
                },
                {
                  "id": "5afd8c59-b741-4e66-be08-35d628df3fe6",
                  "name": "VOL-$$serviceowner-$$function##-V20-",
                  "interface_type": "Protocols::Disk",
                  "depends": true,
                  "connections": [
                    {
                      "id": "2bf55826-81da-4c7c-89da-3a3231259827",
                      "interface_id": "5afd8c59-b741-4e66-be08-35d628df3fe6",
                      "remote_interface_id": "f90e3fbc-4df8-4b1f-a541-bb0a92d3a3ba",
                      "internal": false
                    }
                  ]
                },
                {
                  "id": "ad86e505-5902-4ea3-a603-ecf8f20730dd",
                  "name": "ELBV20MAZ##",
                  "interface_type": "Protocols::LoadBalancer",
                  "depends": true,
                  "connections": [
                    {
                      "id": "baf2afec-979c-4107-a636-a2d16c46d1e5",
                      "interface_id": "ad86e505-5902-4ea3-a603-ecf8f20730dd",
                      "remote_interface_id": "6b6d3835-03e6-49f4-b43e-0543381e3bf0",
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
              "id": "280cce63-8227-41f6-a7b0-44af2ceb741d",
              "name": "EC2-$$servername-$$function##-V20",
              "type": "Services::Compute::Server::AWS",
              "geometry": {
                "x": "54.4",
                "y": "160",
                "width": "56",
                "height": "56"
              },
              "additional_properties": {
                "id": "280cce63-8227-41f6-a7b0-44af2ceb741d",
                "numbered": false,
                "edge": false,
                "service_type": "server",
                "generic_type": "Services::Compute::Server",
                "type": "Services::Compute::Server::AWS",
                "name": "EC2-$$servername-$$function##-V20",
                "drawable": true,
                "draggable": true,
                "interfaces": [
                  {
                    "id": "e978a781-e719-46f9-85f1-54280df2c853",
                    "name": "default",
                    "depends": true,
                    "connections": [
                      {
                        "id": "3d1e4393-94aa-4ac5-a3c5-833c43bd4816",
                        "interface_id": "e978a781-e719-46f9-85f1-54280df2c853",
                        "remote_interface_id": "94c76455-6f91-4128-8db1-d90b323cf891",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::SecurityGroup"
                  },
                  {
                    "id": "71dee424-a9fc-45af-91b6-99b6c0d5590a",
                    "name": "c2",
                    "depends": false,
                    "connections": null,
                    "interface_type": "Protocols::Server"
                  },
                  {
                    "id": "7c8b9e25-9cbe-409b-8611-bca4c3a291f3",
                    "name": "SN-V20-$$servername-##",
                    "depends": true,
                    "connections": [
                      {
                        "id": "b2a748c4-250b-4f7c-ba8a-1bf34dad59a0",
                        "interface_id": "7c8b9e25-9cbe-409b-8611-bca4c3a291f3",
                        "remote_interface_id": "06b21844-962a-48a0-b1d5-4eac6cac5055",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::Subnet"
                  },
                  {
                    "id": "38fb846a-cf32-4884-bf70-484126b2ce99",
                    "name": "prat",
                    "depends": true,
                    "connections": [
                      {
                        "id": "b6508e53-4c60-4a99-8dd9-77390c37569f",
                        "interface_id": "38fb846a-cf32-4884-bf70-484126b2ce99",
                        "remote_interface_id": "cbb835cb-991c-4b69-a081-195c597025bf",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::Vpc"
                  },
                  {
                    "id": "4922588f-318d-4d4d-8ae1-340e677d1461",
                    "name": "VOL-$$serviceowner-$$function##-V20-",
                    "depends": true,
                    "connections": [
                      {
                        "id": "2564d9d2-6586-4bee-8611-3c202832d6ec",
                        "interface_id": "4922588f-318d-4d4d-8ae1-340e677d1461",
                        "remote_interface_id": "f9075414-1e7b-492b-b862-c2de535e4de5",
                        "type": "implicit"
                      },
                      {
                        "id": "a4da5bc8-e63f-4265-bebf-9d6bf3ba77cf",
                        "interface_id": "4922588f-318d-4d4d-8ae1-340e677d1461",
                        "remote_interface_id": "28364273-19f1-46ff-9141-e7d365cfb998",
                        "type": "implicit"
                      },
                      {
                        "id": "1484c28f-5811-4b49-a4c7-883faab5660e",
                        "interface_id": "4922588f-318d-4d4d-8ae1-340e677d1461",
                        "remote_interface_id": "e621f9f3-a4d6-418f-86ae-9e142e48a5e9",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::Disk"
                  }
                ],
                "vpc_id": "16ef4f23-56e2-4f45-a2e3-f20e907bbfcf",
                "primary_key": "",
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
                ],
                "parent_id": "c6fe71d4-e3f1-4de7-ad85-b54a2616a033",
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
                    "value": "ami-4ab1925d",
                    "form_options": {
                      "type": "text",
                      "readonly": "readonly"
                    },
                    "title": "AMI"
                  },
                  {
                    "name": "image_config_id",
                    "value": "a5502d25-3f02-45a6-acb2-453929c3d9bc",
                    "form_options": {
                      "type": "select",
                      "options": {
                        "a5502d25-3f02-45a6-acb2-453929c3d9bc": "q1"
                      },
                      "flavor_ids": {
                        "a5502d25-3f02-45a6-acb2-453929c3d9bc": [
                          "t2.nano",
                          "t2.micro",
                          "t2.small",
                          "t2.medium"
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
                    "value": "t2.nano",
                    "form_options": {
                      "type": "select",
                      "options": [
                        "t2.nano",
                        "t2.micro",
                        "t2.small",
                        "t2.medium"
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
                    "value": false,
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
                    "value": false,
                    "form_options": {
                      "type": "checkbox",
                      "required": false
                    },
                    "title": "EBS Optimized"
                  },
                  {
                    "name": "instance_monitoring",
                    "value": false,
                    "form_options": {
                      "type": "checkbox",
                      "required": false
                    },
                    "title": "Instance Monitoring"
                  },
                  {
                    "name": "key_name",
                    "value": "",
                    "form_options": {
                      "type": "select",
                      "options": [
                        "marketplace-test",
                        "marketplace-test",
                        "marketplace-test"
                      ],
                      "data": [
                        "marketplace-test",
                        "marketplace-test",
                        "marketplace-test"
                      ],
                      "keepfirstblank": true,
                      "firstblanktext": "Proceed without a Key Pair"
                    },
                    "title": "Select Key Pair"
                  },
                  {
                    "name": "original_block_device_mappings",
                    "value": [
                      {
                        "Ebs.VolumeSize": "60",
                        "Ebs.VolumeType": "gp2",
                        "Ebs.Iops": "180",
                        "Ebs.DeleteOnTermination": true,
                        "DevicePath": "/dev/sda1",
                        "Ebs.encrypted": false
                      },
                      {
                        "Ebs.VolumeSize": "1",
                        "Ebs.VolumeType": "gp2",
                        "Ebs.Iops": "3",
                        "Ebs.DeleteOnTermination": true,
                        "DevicePath": "/dev/sdf",
                        "Ebs.encrypted": false
                      },
                      {
                        "Ebs.VolumeSize": "1",
                        "Ebs.VolumeType": "gp2",
                        "Ebs.Iops": "3",
                        "Ebs.DeleteOnTermination": true,
                        "DevicePath": "/dev/sdg",
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
                    "value": "",
                    "form_options": {
                      "type": "select",
                      "options": [
                        ""
                      ],
                      "keepfirstblank": true,
                      "firstblanktext": "None",
                      "required": false
                    },
                    "title": "IAM Role"
                  }
                ]
              },
              "display_name": "c2",
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
                  "value": "ami-4ab1925d",
                  "form_options": {
                    "type": "text",
                    "readonly": "readonly"
                  },
                  "title": "AMI"
                },
                {
                  "name": "image_config_id",
                  "value": "a5502d25-3f02-45a6-acb2-453929c3d9bc",
                  "form_options": {
                    "type": "select",
                    "options": {
                      "a5502d25-3f02-45a6-acb2-453929c3d9bc": "q1"
                    },
                    "flavor_ids": {
                      "a5502d25-3f02-45a6-acb2-453929c3d9bc": [
                        "t2.nano",
                        "t2.micro",
                        "t2.small",
                        "t2.medium"
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
                  "value": "t2.nano",
                  "form_options": {
                    "type": "select",
                    "options": [
                      "t2.nano",
                      "t2.micro",
                      "t2.small",
                      "t2.medium"
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
                  "value": false,
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
                  "value": false,
                  "form_options": {
                    "type": "checkbox",
                    "required": false
                  },
                  "title": "EBS Optimized"
                },
                {
                  "name": "instance_monitoring",
                  "value": false,
                  "form_options": {
                    "type": "checkbox",
                    "required": false
                  },
                  "title": "Instance Monitoring"
                },
                {
                  "name": "key_name",
                  "value": "",
                  "form_options": {
                    "type": "select",
                    "options": [
                      "marketplace-test",
                      "marketplace-test",
                      "marketplace-test"
                    ],
                    "data": [
                      "marketplace-test",
                      "marketplace-test",
                      "marketplace-test"
                    ],
                    "keepfirstblank": true,
                    "firstblanktext": "Proceed without a Key Pair"
                  },
                  "title": "Select Key Pair"
                },
                {
                  "name": "original_block_device_mappings",
                  "value": [
                    {
                      "Ebs.VolumeSize": "60",
                      "Ebs.VolumeType": "gp2",
                      "Ebs.Iops": "180",
                      "Ebs.DeleteOnTermination": true,
                      "DevicePath": "/dev/sda1",
                      "Ebs.encrypted": false
                    },
                    {
                      "Ebs.VolumeSize": "1",
                      "Ebs.VolumeType": "gp2",
                      "Ebs.Iops": "3",
                      "Ebs.DeleteOnTermination": true,
                      "DevicePath": "/dev/sdf",
                      "Ebs.encrypted": false
                    },
                    {
                      "Ebs.VolumeSize": "1",
                      "Ebs.VolumeType": "gp2",
                      "Ebs.Iops": "3",
                      "Ebs.DeleteOnTermination": true,
                      "DevicePath": "/dev/sdg",
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
                  "value": "",
                  "form_options": {
                    "type": "select",
                    "options": [
                      ""
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
                  "id": "e978a781-e719-46f9-85f1-54280df2c853",
                  "name": "default",
                  "interface_type": "Protocols::SecurityGroup",
                  "depends": true,
                  "connections": [
                    {
                      "id": "3d1e4393-94aa-4ac5-a3c5-833c43bd4816",
                      "interface_id": "e978a781-e719-46f9-85f1-54280df2c853",
                      "remote_interface_id": "94c76455-6f91-4128-8db1-d90b323cf891",
                      "internal": false
                    }
                  ]
                },
                {
                  "id": "71dee424-a9fc-45af-91b6-99b6c0d5590a",
                  "name": "c2",
                  "interface_type": "Protocols::Server",
                  "depends": false,
                  "connections": []
                },
                {
                  "id": "7c8b9e25-9cbe-409b-8611-bca4c3a291f3",
                  "name": "SN-V20-$$servername-##",
                  "interface_type": "Protocols::Subnet",
                  "depends": true,
                  "connections": [
                    {
                      "id": "b2a748c4-250b-4f7c-ba8a-1bf34dad59a0",
                      "interface_id": "7c8b9e25-9cbe-409b-8611-bca4c3a291f3",
                      "remote_interface_id": "06b21844-962a-48a0-b1d5-4eac6cac5055",
                      "internal": false
                    }
                  ]
                },
                {
                  "id": "38fb846a-cf32-4884-bf70-484126b2ce99",
                  "name": "prat",
                  "interface_type": "Protocols::Vpc",
                  "depends": true,
                  "connections": [
                    {
                      "id": "b6508e53-4c60-4a99-8dd9-77390c37569f",
                      "interface_id": "38fb846a-cf32-4884-bf70-484126b2ce99",
                      "remote_interface_id": "cbb835cb-991c-4b69-a081-195c597025bf",
                      "internal": false
                    }
                  ]
                },
                {
                  "id": "4922588f-318d-4d4d-8ae1-340e677d1461",
                  "name": "VOL-$$serviceowner-$$function##-V20-",
                  "interface_type": "Protocols::Disk",
                  "depends": true,
                  "connections": [
                    {
                      "id": "2564d9d2-6586-4bee-8611-3c202832d6ec",
                      "interface_id": "4922588f-318d-4d4d-8ae1-340e677d1461",
                      "remote_interface_id": "f9075414-1e7b-492b-b862-c2de535e4de5",
                      "internal": false
                    },
                    {
                      "id": "a4da5bc8-e63f-4265-bebf-9d6bf3ba77cf",
                      "interface_id": "4922588f-318d-4d4d-8ae1-340e677d1461",
                      "remote_interface_id": "28364273-19f1-46ff-9141-e7d365cfb998",
                      "internal": false
                    },
                    {
                      "id": "1484c28f-5811-4b49-a4c7-883faab5660e",
                      "interface_id": "4922588f-318d-4d4d-8ae1-340e677d1461",
                      "remote_interface_id": "e621f9f3-a4d6-418f-86ae-9e142e48a5e9",
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
              "id": "4e621aa6-6a4d-4676-ae41-2b2bd5bb3647",
              "name": "EC2-$$servername-$$function##-V20",
              "type": "Services::Compute::Server::AWS",
              "geometry": {
                "x": "30",
                "y": "40",
                "width": "56",
                "height": "56"
              },
              "additional_properties": {
                "id": "4e621aa6-6a4d-4676-ae41-2b2bd5bb3647",
                "numbered": false,
                "edge": false,
                "service_type": "server",
                "generic_type": "Services::Compute::Server",
                "type": "Services::Compute::Server::AWS",
                "name": "EC2-$$servername-$$function##-V20",
                "drawable": true,
                "draggable": true,
                "interfaces": [
                  {
                    "id": "8bbf418b-b86a-4fb2-a13b-85442690f35e",
                    "name": "default",
                    "depends": true,
                    "connections": [
                      {
                        "id": "b6d4eac5-c4ab-4991-ab24-3cbea635ca21",
                        "interface_id": "8bbf418b-b86a-4fb2-a13b-85442690f35e",
                        "remote_interface_id": "94c76455-6f91-4128-8db1-d90b323cf891",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::SecurityGroup"
                  },
                  {
                    "id": "44802e0e-7257-4ec7-a52c-cac370e9c364",
                    "name": "c2",
                    "depends": false,
                    "connections": null,
                    "interface_type": "Protocols::Server"
                  },
                  {
                    "id": "8fc35f23-96e1-44b1-8af2-211116d304c4",
                    "name": "SN-V20-$$servername-##",
                    "depends": true,
                    "connections": [
                      {
                        "id": "485dbcb3-705e-4fd2-b87f-462a008e5dad",
                        "interface_id": "8fc35f23-96e1-44b1-8af2-211116d304c4",
                        "remote_interface_id": "95af5f53-00a9-4a86-9e69-fba5bf0336f7",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::Subnet"
                  },
                  {
                    "id": "ae15e8c1-28aa-48ff-8dca-fbd7919ab0f7",
                    "name": "prat",
                    "depends": true,
                    "connections": [
                      {
                        "id": "537c8b02-1241-4e9e-9622-8eba04619b8f",
                        "interface_id": "ae15e8c1-28aa-48ff-8dca-fbd7919ab0f7",
                        "remote_interface_id": "cbb835cb-991c-4b69-a081-195c597025bf",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::Vpc"
                  },
                  {
                    "id": "9e4f7686-04a8-494b-9b05-db35130e95a7",
                    "name": "VOL-$$serviceowner-$$function##-V20-",
                    "depends": true,
                    "connections": [
                      {
                        "id": "0b1b221f-eadd-4e0c-98e2-741d1cf9c65d",
                        "interface_id": "9e4f7686-04a8-494b-9b05-db35130e95a7",
                        "remote_interface_id": "cbc0f40d-5818-4597-896c-be4c970c8433",
                        "type": "implicit"
                      },
                      {
                        "id": "ef228346-f6e9-4fd2-96bd-7a70d663dc4d",
                        "interface_id": "9e4f7686-04a8-494b-9b05-db35130e95a7",
                        "remote_interface_id": "7be345e1-309a-459f-ac24-6fcff988502c",
                        "type": "implicit"
                      },
                      {
                        "id": "7dba74d1-1579-499f-9e7d-3bd98db014cc",
                        "interface_id": "9e4f7686-04a8-494b-9b05-db35130e95a7",
                        "remote_interface_id": "b92d66d6-8929-41f6-8e24-107214a083a1",
                        "type": "implicit"
                      },
                      {
                        "id": "a6e48a11-2bdb-4e74-9877-3f8a36b9122b",
                        "interface_id": "9e4f7686-04a8-494b-9b05-db35130e95a7",
                        "remote_interface_id": "8ba53d8e-82e0-4c10-ba36-5457c38e65a1",
                        "type": "implicit"
                      },
                      {
                        "id": "b2d6d541-6ee8-4319-aacd-4cbf014a82f3",
                        "interface_id": "9e4f7686-04a8-494b-9b05-db35130e95a7",
                        "remote_interface_id": "dc580866-77d5-4643-90a0-31254a642215",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::Disk"
                  }
                ],
                "vpc_id": "16ef4f23-56e2-4f45-a2e3-f20e907bbfcf",
                "primary_key": "",
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
                ],
                "parent_id": "0dbde654-1252-49b0-978d-f0fab5713cc5",
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
                    "value": "ami-4ab1925d",
                    "form_options": {
                      "type": "text",
                      "readonly": "readonly"
                    },
                    "title": "AMI"
                  },
                  {
                    "name": "image_config_id",
                    "value": "a5502d25-3f02-45a6-acb2-453929c3d9bc",
                    "form_options": {
                      "type": "select",
                      "options": {
                        "a5502d25-3f02-45a6-acb2-453929c3d9bc": "q1"
                      },
                      "flavor_ids": {
                        "a5502d25-3f02-45a6-acb2-453929c3d9bc": [
                          "t2.nano",
                          "t2.micro",
                          "t2.small",
                          "t2.medium"
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
                    "value": "t2.nano",
                    "form_options": {
                      "type": "select",
                      "options": [
                        "t2.nano",
                        "t2.micro",
                        "t2.small",
                        "t2.medium"
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
                    "value": false,
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
                    "value": false,
                    "form_options": {
                      "type": "checkbox",
                      "required": false
                    },
                    "title": "EBS Optimized"
                  },
                  {
                    "name": "instance_monitoring",
                    "value": false,
                    "form_options": {
                      "type": "checkbox",
                      "required": false
                    },
                    "title": "Instance Monitoring"
                  },
                  {
                    "name": "key_name",
                    "value": "",
                    "form_options": {
                      "type": "select",
                      "options": [
                        "marketplace-test",
                        "marketplace-test",
                        "marketplace-test"
                      ],
                      "data": [
                        "marketplace-test",
                        "marketplace-test",
                        "marketplace-test"
                      ],
                      "keepfirstblank": true,
                      "firstblanktext": "Proceed without a Key Pair"
                    },
                    "title": "Select Key Pair"
                  },
                  {
                    "name": "original_block_device_mappings",
                    "value": [
                      {
                        "Ebs.VolumeSize": "60",
                        "Ebs.VolumeType": "gp2",
                        "Ebs.Iops": "180",
                        "Ebs.DeleteOnTermination": true,
                        "DevicePath": "/dev/sda1",
                        "Ebs.encrypted": false
                      },
                      {
                        "Ebs.VolumeSize": "1",
                        "Ebs.VolumeType": "gp2",
                        "Ebs.Iops": "3",
                        "Ebs.DeleteOnTermination": true,
                        "DevicePath": "/dev/sdf",
                        "Ebs.encrypted": false
                      },
                      {
                        "Ebs.VolumeSize": "1",
                        "Ebs.VolumeType": "gp2",
                        "Ebs.Iops": "3",
                        "Ebs.DeleteOnTermination": true,
                        "DevicePath": "/dev/sdg",
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
                    "value": "",
                    "form_options": {
                      "type": "select",
                      "options": [
                        ""
                      ],
                      "keepfirstblank": true,
                      "firstblanktext": "None",
                      "required": false
                    },
                    "title": "IAM Role"
                  }
                ]
              },
              "display_name": "c2",
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
                  "value": "ami-4ab1925d",
                  "form_options": {
                    "type": "text",
                    "readonly": "readonly"
                  },
                  "title": "AMI"
                },
                {
                  "name": "image_config_id",
                  "value": "a5502d25-3f02-45a6-acb2-453929c3d9bc",
                  "form_options": {
                    "type": "select",
                    "options": {
                      "a5502d25-3f02-45a6-acb2-453929c3d9bc": "q1"
                    },
                    "flavor_ids": {
                      "a5502d25-3f02-45a6-acb2-453929c3d9bc": [
                        "t2.nano",
                        "t2.micro",
                        "t2.small",
                        "t2.medium"
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
                  "value": "t2.nano",
                  "form_options": {
                    "type": "select",
                    "options": [
                      "t2.nano",
                      "t2.micro",
                      "t2.small",
                      "t2.medium"
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
                  "value": false,
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
                  "value": false,
                  "form_options": {
                    "type": "checkbox",
                    "required": false
                  },
                  "title": "EBS Optimized"
                },
                {
                  "name": "instance_monitoring",
                  "value": false,
                  "form_options": {
                    "type": "checkbox",
                    "required": false
                  },
                  "title": "Instance Monitoring"
                },
                {
                  "name": "key_name",
                  "value": "",
                  "form_options": {
                    "type": "select",
                    "options": [
                      "marketplace-test",
                      "marketplace-test",
                      "marketplace-test"
                    ],
                    "data": [
                      "marketplace-test",
                      "marketplace-test",
                      "marketplace-test"
                    ],
                    "keepfirstblank": true,
                    "firstblanktext": "Proceed without a Key Pair"
                  },
                  "title": "Select Key Pair"
                },
                {
                  "name": "original_block_device_mappings",
                  "value": [
                    {
                      "Ebs.VolumeSize": "60",
                      "Ebs.VolumeType": "gp2",
                      "Ebs.Iops": "180",
                      "Ebs.DeleteOnTermination": true,
                      "DevicePath": "/dev/sda1",
                      "Ebs.encrypted": false
                    },
                    {
                      "Ebs.VolumeSize": "1",
                      "Ebs.VolumeType": "gp2",
                      "Ebs.Iops": "3",
                      "Ebs.DeleteOnTermination": true,
                      "DevicePath": "/dev/sdf",
                      "Ebs.encrypted": false
                    },
                    {
                      "Ebs.VolumeSize": "1",
                      "Ebs.VolumeType": "gp2",
                      "Ebs.Iops": "3",
                      "Ebs.DeleteOnTermination": true,
                      "DevicePath": "/dev/sdg",
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
                  "value": "",
                  "form_options": {
                    "type": "select",
                    "options": [
                      ""
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
                  "id": "8bbf418b-b86a-4fb2-a13b-85442690f35e",
                  "name": "default",
                  "interface_type": "Protocols::SecurityGroup",
                  "depends": true,
                  "connections": [
                    {
                      "id": "b6d4eac5-c4ab-4991-ab24-3cbea635ca21",
                      "interface_id": "8bbf418b-b86a-4fb2-a13b-85442690f35e",
                      "remote_interface_id": "94c76455-6f91-4128-8db1-d90b323cf891",
                      "internal": false
                    }
                  ]
                },
                {
                  "id": "44802e0e-7257-4ec7-a52c-cac370e9c364",
                  "name": "c2",
                  "interface_type": "Protocols::Server",
                  "depends": false,
                  "connections": []
                },
                {
                  "id": "8fc35f23-96e1-44b1-8af2-211116d304c4",
                  "name": "SN-V20-$$servername-##",
                  "interface_type": "Protocols::Subnet",
                  "depends": true,
                  "connections": [
                    {
                      "id": "485dbcb3-705e-4fd2-b87f-462a008e5dad",
                      "interface_id": "8fc35f23-96e1-44b1-8af2-211116d304c4",
                      "remote_interface_id": "95af5f53-00a9-4a86-9e69-fba5bf0336f7",
                      "internal": false
                    }
                  ]
                },
                {
                  "id": "ae15e8c1-28aa-48ff-8dca-fbd7919ab0f7",
                  "name": "prat",
                  "interface_type": "Protocols::Vpc",
                  "depends": true,
                  "connections": [
                    {
                      "id": "537c8b02-1241-4e9e-9622-8eba04619b8f",
                      "interface_id": "ae15e8c1-28aa-48ff-8dca-fbd7919ab0f7",
                      "remote_interface_id": "cbb835cb-991c-4b69-a081-195c597025bf",
                      "internal": false
                    }
                  ]
                },
                {
                  "id": "9e4f7686-04a8-494b-9b05-db35130e95a7",
                  "name": "VOL-$$serviceowner-$$function##-V20-",
                  "interface_type": "Protocols::Disk",
                  "depends": true,
                  "connections": [
                    {
                      "id": "0b1b221f-eadd-4e0c-98e2-741d1cf9c65d",
                      "interface_id": "9e4f7686-04a8-494b-9b05-db35130e95a7",
                      "remote_interface_id": "cbc0f40d-5818-4597-896c-be4c970c8433",
                      "internal": false
                    },
                    {
                      "id": "ef228346-f6e9-4fd2-96bd-7a70d663dc4d",
                      "interface_id": "9e4f7686-04a8-494b-9b05-db35130e95a7",
                      "remote_interface_id": "7be345e1-309a-459f-ac24-6fcff988502c",
                      "internal": false
                    },
                    {
                      "id": "7dba74d1-1579-499f-9e7d-3bd98db014cc",
                      "interface_id": "9e4f7686-04a8-494b-9b05-db35130e95a7",
                      "remote_interface_id": "b92d66d6-8929-41f6-8e24-107214a083a1",
                      "internal": false
                    },
                    {
                      "id": "a6e48a11-2bdb-4e74-9877-3f8a36b9122b",
                      "interface_id": "9e4f7686-04a8-494b-9b05-db35130e95a7",
                      "remote_interface_id": "8ba53d8e-82e0-4c10-ba36-5457c38e65a1",
                      "internal": false
                    },
                    {
                      "id": "b2d6d541-6ee8-4319-aacd-4cbf014a82f3",
                      "interface_id": "9e4f7686-04a8-494b-9b05-db35130e95a7",
                      "remote_interface_id": "dc580866-77d5-4643-90a0-31254a642215",
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
              "id": "7bc345f2-d337-46ff-843f-55d54d3f91fc",
              "name": "EC2-$$servername-$$function##-V20",
              "type": "Services::Compute::Server::AWS",
              "geometry": {
                "x": "54.4",
                "y": "260",
                "width": "56",
                "height": "56"
              },
              "additional_properties": {
                "id": "7bc345f2-d337-46ff-843f-55d54d3f91fc",
                "numbered": false,
                "edge": false,
                "service_type": "server",
                "generic_type": "Services::Compute::Server",
                "type": "Services::Compute::Server::AWS",
                "name": "EC2-$$servername-$$function##-V20",
                "drawable": true,
                "draggable": true,
                "interfaces": [
                  {
                    "id": "5a28b469-e42d-486a-9ab2-20e7854d065c",
                    "name": "default",
                    "depends": true,
                    "connections": [
                      {
                        "id": "1ac14f46-0d1d-4d4f-9222-87b476089c9e",
                        "interface_id": "5a28b469-e42d-486a-9ab2-20e7854d065c",
                        "remote_interface_id": "94c76455-6f91-4128-8db1-d90b323cf891",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::SecurityGroup"
                  },
                  {
                    "id": "dea87cd3-0607-4f26-8039-6ac2f292a31f",
                    "name": "c2",
                    "depends": false,
                    "connections": null,
                    "interface_type": "Protocols::Server"
                  },
                  {
                    "id": "e2b76f0c-1feb-45a5-a44f-a0e9a846910c",
                    "name": "SN-V20-$$servername-##",
                    "depends": true,
                    "connections": [
                      {
                        "id": "736d9d78-c63b-44d3-b4b9-63652c13112b",
                        "interface_id": "e2b76f0c-1feb-45a5-a44f-a0e9a846910c",
                        "remote_interface_id": "06b21844-962a-48a0-b1d5-4eac6cac5055",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::Subnet"
                  },
                  {
                    "id": "92b5f343-ad13-4fe3-9a21-7e8faef1559e",
                    "name": "prat",
                    "depends": true,
                    "connections": [
                      {
                        "id": "cb355a71-faad-483f-b49a-85c5ffe5783a",
                        "interface_id": "92b5f343-ad13-4fe3-9a21-7e8faef1559e",
                        "remote_interface_id": "cbb835cb-991c-4b69-a081-195c597025bf",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::Vpc"
                  },
                  {
                    "id": "964d77c4-58fc-4d7e-b152-a8cf53514e16",
                    "name": "VOL-$$serviceowner-$$function##-V20-",
                    "depends": true,
                    "connections": [
                      {
                        "id": "2001bd16-d28b-4149-86d2-049752cbddc4",
                        "interface_id": "964d77c4-58fc-4d7e-b152-a8cf53514e16",
                        "remote_interface_id": "00b8306b-5256-4a02-8c86-75a54f959ab5",
                        "type": "implicit"
                      },
                      {
                        "id": "d6e83405-0ff0-4ecf-9d18-e47e1628c6bc",
                        "interface_id": "964d77c4-58fc-4d7e-b152-a8cf53514e16",
                        "remote_interface_id": "6eff111e-1b94-4d8a-b641-156187d67b27",
                        "type": "implicit"
                      },
                      {
                        "id": "33ed21be-57bd-4d67-9afd-014f16a02bd7",
                        "interface_id": "964d77c4-58fc-4d7e-b152-a8cf53514e16",
                        "remote_interface_id": "efae9795-d005-4b56-bbac-e70387cf3d34",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::Disk"
                  }
                ],
                "vpc_id": "16ef4f23-56e2-4f45-a2e3-f20e907bbfcf",
                "primary_key": "",
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
                ],
                "parent_id": "c6fe71d4-e3f1-4de7-ad85-b54a2616a033",
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
                    "value": "ami-4ab1925d",
                    "form_options": {
                      "type": "text",
                      "readonly": "readonly"
                    },
                    "title": "AMI"
                  },
                  {
                    "name": "image_config_id",
                    "value": "a5502d25-3f02-45a6-acb2-453929c3d9bc",
                    "form_options": {
                      "type": "select",
                      "options": {
                        "a5502d25-3f02-45a6-acb2-453929c3d9bc": "q1"
                      },
                      "flavor_ids": {
                        "a5502d25-3f02-45a6-acb2-453929c3d9bc": [
                          "t2.nano",
                          "t2.micro",
                          "t2.small",
                          "t2.medium"
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
                    "value": "t2.nano",
                    "form_options": {
                      "type": "select",
                      "options": [
                        "t2.nano",
                        "t2.micro",
                        "t2.small",
                        "t2.medium"
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
                    "value": false,
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
                    "value": false,
                    "form_options": {
                      "type": "checkbox",
                      "required": false
                    },
                    "title": "EBS Optimized"
                  },
                  {
                    "name": "instance_monitoring",
                    "value": false,
                    "form_options": {
                      "type": "checkbox",
                      "required": false
                    },
                    "title": "Instance Monitoring"
                  },
                  {
                    "name": "key_name",
                    "value": "",
                    "form_options": {
                      "type": "select",
                      "options": [
                        "marketplace-test",
                        "marketplace-test",
                        "marketplace-test"
                      ],
                      "data": [
                        "marketplace-test",
                        "marketplace-test",
                        "marketplace-test"
                      ],
                      "keepfirstblank": true,
                      "firstblanktext": "Proceed without a Key Pair"
                    },
                    "title": "Select Key Pair"
                  },
                  {
                    "name": "original_block_device_mappings",
                    "value": [
                      {
                        "Ebs.VolumeSize": "60",
                        "Ebs.VolumeType": "gp2",
                        "Ebs.Iops": "180",
                        "Ebs.DeleteOnTermination": true,
                        "DevicePath": "/dev/sda1",
                        "Ebs.encrypted": false
                      },
                      {
                        "Ebs.VolumeSize": "1",
                        "Ebs.VolumeType": "gp2",
                        "Ebs.Iops": "3",
                        "Ebs.DeleteOnTermination": true,
                        "DevicePath": "/dev/sdf",
                        "Ebs.encrypted": false
                      },
                      {
                        "Ebs.VolumeSize": "1",
                        "Ebs.VolumeType": "gp2",
                        "Ebs.Iops": "3",
                        "Ebs.DeleteOnTermination": true,
                        "DevicePath": "/dev/sdg",
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
                    "value": "",
                    "form_options": {
                      "type": "select",
                      "options": [
                        ""
                      ],
                      "keepfirstblank": true,
                      "firstblanktext": "None",
                      "required": false
                    },
                    "title": "IAM Role"
                  }
                ]
              },
              "display_name": "c2",
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
                  "value": "ami-4ab1925d",
                  "form_options": {
                    "type": "text",
                    "readonly": "readonly"
                  },
                  "title": "AMI"
                },
                {
                  "name": "image_config_id",
                  "value": "a5502d25-3f02-45a6-acb2-453929c3d9bc",
                  "form_options": {
                    "type": "select",
                    "options": {
                      "a5502d25-3f02-45a6-acb2-453929c3d9bc": "q1"
                    },
                    "flavor_ids": {
                      "a5502d25-3f02-45a6-acb2-453929c3d9bc": [
                        "t2.nano",
                        "t2.micro",
                        "t2.small",
                        "t2.medium"
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
                  "value": "t2.nano",
                  "form_options": {
                    "type": "select",
                    "options": [
                      "t2.nano",
                      "t2.micro",
                      "t2.small",
                      "t2.medium"
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
                  "value": false,
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
                  "value": false,
                  "form_options": {
                    "type": "checkbox",
                    "required": false
                  },
                  "title": "EBS Optimized"
                },
                {
                  "name": "instance_monitoring",
                  "value": false,
                  "form_options": {
                    "type": "checkbox",
                    "required": false
                  },
                  "title": "Instance Monitoring"
                },
                {
                  "name": "key_name",
                  "value": "",
                  "form_options": {
                    "type": "select",
                    "options": [
                      "marketplace-test",
                      "marketplace-test",
                      "marketplace-test"
                    ],
                    "data": [
                      "marketplace-test",
                      "marketplace-test",
                      "marketplace-test"
                    ],
                    "keepfirstblank": true,
                    "firstblanktext": "Proceed without a Key Pair"
                  },
                  "title": "Select Key Pair"
                },
                {
                  "name": "original_block_device_mappings",
                  "value": [
                    {
                      "Ebs.VolumeSize": "60",
                      "Ebs.VolumeType": "gp2",
                      "Ebs.Iops": "180",
                      "Ebs.DeleteOnTermination": true,
                      "DevicePath": "/dev/sda1",
                      "Ebs.encrypted": false
                    },
                    {
                      "Ebs.VolumeSize": "1",
                      "Ebs.VolumeType": "gp2",
                      "Ebs.Iops": "3",
                      "Ebs.DeleteOnTermination": true,
                      "DevicePath": "/dev/sdf",
                      "Ebs.encrypted": false
                    },
                    {
                      "Ebs.VolumeSize": "1",
                      "Ebs.VolumeType": "gp2",
                      "Ebs.Iops": "3",
                      "Ebs.DeleteOnTermination": true,
                      "DevicePath": "/dev/sdg",
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
                  "value": "",
                  "form_options": {
                    "type": "select",
                    "options": [
                      ""
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
                  "id": "e2b76f0c-1feb-45a5-a44f-a0e9a846910c",
                  "name": "SN-V20-$$servername-##",
                  "interface_type": "Protocols::Subnet",
                  "depends": true,
                  "connections": [
                    {
                      "id": "736d9d78-c63b-44d3-b4b9-63652c13112b",
                      "interface_id": "e2b76f0c-1feb-45a5-a44f-a0e9a846910c",
                      "remote_interface_id": "06b21844-962a-48a0-b1d5-4eac6cac5055",
                      "internal": false
                    }
                  ]
                },
                {
                  "id": "92b5f343-ad13-4fe3-9a21-7e8faef1559e",
                  "name": "prat",
                  "interface_type": "Protocols::Vpc",
                  "depends": true,
                  "connections": [
                    {
                      "id": "cb355a71-faad-483f-b49a-85c5ffe5783a",
                      "interface_id": "92b5f343-ad13-4fe3-9a21-7e8faef1559e",
                      "remote_interface_id": "cbb835cb-991c-4b69-a081-195c597025bf",
                      "internal": false
                    }
                  ]
                },
                {
                  "id": "964d77c4-58fc-4d7e-b152-a8cf53514e16",
                  "name": "VOL-$$serviceowner-$$function##-V20-",
                  "interface_type": "Protocols::Disk",
                  "depends": true,
                  "connections": [
                    {
                      "id": "2001bd16-d28b-4149-86d2-049752cbddc4",
                      "interface_id": "964d77c4-58fc-4d7e-b152-a8cf53514e16",
                      "remote_interface_id": "00b8306b-5256-4a02-8c86-75a54f959ab5",
                      "internal": false
                    },
                    {
                      "id": "d6e83405-0ff0-4ecf-9d18-e47e1628c6bc",
                      "interface_id": "964d77c4-58fc-4d7e-b152-a8cf53514e16",
                      "remote_interface_id": "6eff111e-1b94-4d8a-b641-156187d67b27",
                      "internal": false
                    },
                    {
                      "id": "33ed21be-57bd-4d67-9afd-014f16a02bd7",
                      "interface_id": "964d77c4-58fc-4d7e-b152-a8cf53514e16",
                      "remote_interface_id": "efae9795-d005-4b56-bbac-e70387cf3d34",
                      "internal": false
                    }
                  ]
                },
                {
                  "id": "5a28b469-e42d-486a-9ab2-20e7854d065c",
                  "name": "default",
                  "interface_type": "Protocols::SecurityGroup",
                  "depends": true,
                  "connections": [
                    {
                      "id": "1ac14f46-0d1d-4d4f-9222-87b476089c9e",
                      "interface_id": "5a28b469-e42d-486a-9ab2-20e7854d065c",
                      "remote_interface_id": "94c76455-6f91-4128-8db1-d90b323cf891",
                      "internal": false
                    }
                  ]
                },
                {
                  "id": "dea87cd3-0607-4f26-8039-6ac2f292a31f",
                  "name": "c2",
                  "interface_type": "Protocols::Server",
                  "depends": false,
                  "connections": []
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
              "id": "9c269f6c-7487-4ea6-8dd8-e25fef321f28",
              "name": "sng-v20-$$serviceowner-$$nameenvironment##",
              "type": "Services::Network::SubnetGroup::AWS",
              "geometry": {
                "x": "698.32",
                "y": "794.64",
                "width": "160",
                "height": "160"
              },
              "additional_properties": {
                "id": "9c269f6c-7487-4ea6-8dd8-e25fef321f28",
                "numbered": false,
                "edge": false,
                "service_type": "subnetgroup",
                "generic_type": "Services::Network::SubnetGroup",
                "type": "Services::Network::SubnetGroup::AWS",
                "name": "sng-v20-$$serviceowner-$$nameenvironment##",
                "drawable": true,
                "draggable": true,
                "interfaces": [
                  {
                    "id": "6b555015-afb5-4886-b50d-a763f6b71a0f",
                    "name": "subnet-group",
                    "depends": false,
                    "connections": null,
                    "interface_type": "Protocols::SubnetGroup"
                  },
                  {
                    "id": "97230020-7caa-4a0c-bc88-073853f89329",
                    "name": "prat",
                    "depends": true,
                    "connections": [
                      {
                        "id": "01990666-bd7f-4eb2-bb31-cd34ef303929",
                        "interface_id": "97230020-7caa-4a0c-bc88-073853f89329",
                        "remote_interface_id": "cbb835cb-991c-4b69-a081-195c597025bf",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::Vpc"
                  },
                  {
                    "id": "2ed7050c-85d1-476e-9d2e-5972b4494927",
                    "name": "SN-V20-$$servername-##",
                    "depends": true,
                    "connections": [
                      {
                        "id": "76461d20-5d63-432e-83ae-5c21e57c10d9",
                        "interface_id": "2ed7050c-85d1-476e-9d2e-5972b4494927",
                        "remote_interface_id": "b2c454b8-ea36-48d2-9b11-140cc3b1a4a4",
                        "type": "implicit"
                      },
                      {
                        "id": "fcbca36d-0450-4d46-83cb-7217b26b4822",
                        "interface_id": "2ed7050c-85d1-476e-9d2e-5972b4494927",
                        "remote_interface_id": "00a5f387-643e-46bb-949c-3062b5bfebec",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::Subnet"
                  }
                ],
                "vpc_id": "16ef4f23-56e2-4f45-a2e3-f20e907bbfcf",
                "primary_key": "",
                "provides": [
                  {
                    "name": "subnet",
                    "type": "Protocols::SubnetGroup",
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
                "parent_id": "9d27f097-3fe8-4907-b8aa-305ba973b6da",
                "properties": [
                  {
                    "name": "description",
                    "value": "Subnet Group Description",
                    "form_options": {
                      "type": "text"
                    },
                    "title": "Description"
                  },
                  {
                    "name": "uniq_provider_id",
                    "value": "14823920365711803",
                    "form_options": {
                      "type": "hidden"
                    },
                    "title": "Uniq Provider Id"
                  },
                  {
                    "name": "provider_id",
                    "form_options": {
                      "type": "hidden"
                    },
                    "title": "Provider Id"
                  },
                  {
                    "name": "subnet_ids",
                    "value": [],
                    "form_options": {
                      "type": "array",
                      "options": null
                    },
                    "title": "Subnet Ids"
                  },
                  {
                    "name": "subnet_service_ids",
                    "value": [
                      "0af16444-1472-4db3-9a53-8c4707e4f2b6",
                      "f167f871-edca-47cc-b2c6-9e168f152c77"
                    ],
                    "form_options": {
                      "type": "array",
                      "options": [
                        {
                          "id": "e395e5df-df3c-4980-b5e1-553416679ddd",
                          "provider_id": null,
                          "provider_vpc_id": null,
                          "name": "SN-V20-$$servername-##",
                          "vpc_id": "16ef4f23-56e2-4f45-a2e3-f20e907bbfcf",
                          "cidr_block": "10.0.192.0/19",
                          "available_ip": null,
                          "availability_zone": "us-east-1d",
                          "tags": {},
                          "provider_data": null,
                          "adapter_id": "906b379e-3f78-4ade-bc64-587a8919bd78",
                          "account_id": "e7028ea2-4bd7-428b-b538-1bf0bfb4cb76",
                          "region_id": "720368ad-d9f2-4bd8-8cf5-01f9c21a6807",
                          "state": "pending",
                          "data": null
                        },
                        {
                          "id": "acca5944-496f-4ae5-ab34-30e2e736f122",
                          "provider_id": null,
                          "provider_vpc_id": null,
                          "name": "SN-V20-$$servername-##",
                          "vpc_id": "16ef4f23-56e2-4f45-a2e3-f20e907bbfcf",
                          "cidr_block": "10.0.224.0/19",
                          "available_ip": null,
                          "availability_zone": "us-east-1b",
                          "tags": {},
                          "provider_data": null,
                          "adapter_id": "906b379e-3f78-4ade-bc64-587a8919bd78",
                          "account_id": "e7028ea2-4bd7-428b-b538-1bf0bfb4cb76",
                          "region_id": "720368ad-d9f2-4bd8-8cf5-01f9c21a6807",
                          "state": "pending",
                          "data": null
                        }
                      ]
                    },
                    "title": "Subnet Service Ids"
                  },
                  {
                    "name": "vpc_id",
                    "value": "16ef4f23-56e2-4f45-a2e3-f20e907bbfcf",
                    "form_options": {
                      "type": "hidden"
                    },
                    "title": "VPC Id"
                  }
                ]
              },
              "existing_subnet_group": true,
              "properties": [
                {
                  "name": "description",
                  "value": "Subnet Group Description",
                  "form_options": {
                    "type": "text"
                  },
                  "title": "Description"
                },
                {
                  "name": "uniq_provider_id",
                  "value": "14823920365711803",
                  "form_options": {
                    "type": "hidden"
                  },
                  "title": "Uniq Provider Id"
                },
                {
                  "name": "provider_id",
                  "form_options": {
                    "type": "hidden"
                  },
                  "title": "Provider Id"
                },
                {
                  "name": "subnet_ids",
                  "value": [],
                  "form_options": {
                    "type": "array",
                    "options": null
                  },
                  "title": "Subnet Ids"
                },
                {
                  "name": "subnet_service_ids",
                  "value": [
                    "0af16444-1472-4db3-9a53-8c4707e4f2b6",
                    "f167f871-edca-47cc-b2c6-9e168f152c77"
                  ],
                  "form_options": {
                    "type": "array",
                    "options": [
                      {
                        "id": "e395e5df-df3c-4980-b5e1-553416679ddd",
                        "provider_id": null,
                        "provider_vpc_id": null,
                        "name": "SN-V20-$$servername-##",
                        "vpc_id": "16ef4f23-56e2-4f45-a2e3-f20e907bbfcf",
                        "cidr_block": "10.0.192.0/19",
                        "available_ip": null,
                        "availability_zone": "us-east-1d",
                        "tags": {},
                        "provider_data": null,
                        "adapter_id": "906b379e-3f78-4ade-bc64-587a8919bd78",
                        "account_id": "e7028ea2-4bd7-428b-b538-1bf0bfb4cb76",
                        "region_id": "720368ad-d9f2-4bd8-8cf5-01f9c21a6807",
                        "state": "pending",
                        "data": null
                      },
                      {
                        "id": "acca5944-496f-4ae5-ab34-30e2e736f122",
                        "provider_id": null,
                        "provider_vpc_id": null,
                        "name": "SN-V20-$$servername-##",
                        "vpc_id": "16ef4f23-56e2-4f45-a2e3-f20e907bbfcf",
                        "cidr_block": "10.0.224.0/19",
                        "available_ip": null,
                        "availability_zone": "us-east-1b",
                        "tags": {},
                        "provider_data": null,
                        "adapter_id": "906b379e-3f78-4ade-bc64-587a8919bd78",
                        "account_id": "e7028ea2-4bd7-428b-b538-1bf0bfb4cb76",
                        "region_id": "720368ad-d9f2-4bd8-8cf5-01f9c21a6807",
                        "state": "pending",
                        "data": null
                      }
                    ]
                  },
                  "title": "Subnet Service Ids"
                },
                {
                  "name": "vpc_id",
                  "value": "16ef4f23-56e2-4f45-a2e3-f20e907bbfcf",
                  "form_options": {
                    "type": "hidden"
                  },
                  "title": "VPC Id"
                }
              ],
              "interfaces": [
                {
                  "id": "6b555015-afb5-4886-b50d-a763f6b71a0f",
                  "name": "subnet-group",
                  "interface_type": "Protocols::SubnetGroup",
                  "depends": false,
                  "connections": []
                },
                {
                  "id": "97230020-7caa-4a0c-bc88-073853f89329",
                  "name": "prat",
                  "interface_type": "Protocols::Vpc",
                  "depends": true,
                  "connections": [
                    {
                      "id": "01990666-bd7f-4eb2-bb31-cd34ef303929",
                      "interface_id": "97230020-7caa-4a0c-bc88-073853f89329",
                      "remote_interface_id": "cbb835cb-991c-4b69-a081-195c597025bf",
                      "internal": false
                    }
                  ]
                },
                {
                  "id": "2ed7050c-85d1-476e-9d2e-5972b4494927",
                  "name": "SN-V20-$$servername-##",
                  "interface_type": "Protocols::Subnet",
                  "depends": true,
                  "connections": [
                    {
                      "id": "76461d20-5d63-432e-83ae-5c21e57c10d9",
                      "interface_id": "2ed7050c-85d1-476e-9d2e-5972b4494927",
                      "remote_interface_id": "b2c454b8-ea36-48d2-9b11-140cc3b1a4a4",
                      "internal": false
                    },
                    {
                      "id": "fcbca36d-0450-4d46-83cb-7217b26b4822",
                      "interface_id": "2ed7050c-85d1-476e-9d2e-5972b4494927",
                      "remote_interface_id": "00a5f387-643e-46bb-949c-3062b5bfebec",
                      "internal": false
                    }
                  ]
                }
              ],
              "provides": [
                {
                  "name": "subnet",
                  "type": "Protocols::SubnetGroup",
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
              "id": "a295c0a9-6d95-4d9d-b4d5-58cf2b85ea36",
              "name": "sng-v20-$$serviceowner-$$nameenvironment##",
              "type": "Services::Network::SubnetGroup::AWS",
              "geometry": {
                "x": "714.64",
                "y": "552.72",
                "width": "210.96",
                "height": "150"
              },
              "additional_properties": {
                "id": "a295c0a9-6d95-4d9d-b4d5-58cf2b85ea36",
                "numbered": false,
                "edge": false,
                "service_type": "subnetgroup",
                "generic_type": "Services::Network::SubnetGroup",
                "type": "Services::Network::SubnetGroup::AWS",
                "name": "sng-v20-$$serviceowner-$$nameenvironment##",
                "drawable": true,
                "draggable": true,
                "interfaces": [
                  {
                    "id": "f5fe0748-61fa-4fa1-b254-acf961440146",
                    "name": "subnet-group",
                    "depends": false,
                    "connections": null,
                    "interface_type": "Protocols::SubnetGroup"
                  },
                  {
                    "id": "3e2a9303-c313-4bb2-bee8-4e98bae6df06",
                    "name": "prat",
                    "depends": true,
                    "connections": [
                      {
                        "id": "0ca6b222-0102-4ed0-b7b0-9b8c9d27a79f",
                        "interface_id": "3e2a9303-c313-4bb2-bee8-4e98bae6df06",
                        "remote_interface_id": "cbb835cb-991c-4b69-a081-195c597025bf",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::Vpc"
                  },
                  {
                    "id": "02172b1b-618d-4b20-83b8-666202534e60",
                    "name": "SN-V20-$$servername-##",
                    "depends": true,
                    "connections": [
                      {
                        "id": "45aaef85-9a53-42f2-b331-3dc9ed03751e",
                        "interface_id": "02172b1b-618d-4b20-83b8-666202534e60",
                        "remote_interface_id": "b2c454b8-ea36-48d2-9b11-140cc3b1a4a4",
                        "type": "implicit"
                      },
                      {
                        "id": "3c2d31fd-5f06-43e4-802b-bddb20096bad",
                        "interface_id": "02172b1b-618d-4b20-83b8-666202534e60",
                        "remote_interface_id": "00a5f387-643e-46bb-949c-3062b5bfebec",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::Subnet"
                  }
                ],
                "vpc_id": "16ef4f23-56e2-4f45-a2e3-f20e907bbfcf",
                "primary_key": "",
                "provides": [
                  {
                    "name": "subnet",
                    "type": "Protocols::SubnetGroup",
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
                "parent_id": "9d27f097-3fe8-4907-b8aa-305ba973b6da",
                "properties": [
                  {
                    "name": "description",
                    "value": "Subnet Group Description",
                    "form_options": {
                      "type": "text"
                    },
                    "title": "Description"
                  },
                  {
                    "name": "uniq_provider_id",
                    "value": "14823920367535486",
                    "form_options": {
                      "type": "hidden"
                    },
                    "title": "Uniq Provider Id"
                  },
                  {
                    "name": "provider_id",
                    "form_options": {
                      "type": "hidden"
                    },
                    "title": "Provider Id"
                  },
                  {
                    "name": "subnet_ids",
                    "value": [],
                    "form_options": {
                      "type": "array",
                      "options": null
                    },
                    "title": "Subnet Ids"
                  },
                  {
                    "name": "subnet_service_ids",
                    "value": [
                      "0af16444-1472-4db3-9a53-8c4707e4f2b6",
                      "f167f871-edca-47cc-b2c6-9e168f152c77"
                    ],
                    "form_options": {
                      "type": "array",
                      "options": [
                        {
                          "id": "e395e5df-df3c-4980-b5e1-553416679ddd",
                          "provider_id": null,
                          "provider_vpc_id": null,
                          "name": "SN-V20-$$servername-##",
                          "vpc_id": "16ef4f23-56e2-4f45-a2e3-f20e907bbfcf",
                          "cidr_block": "10.0.192.0/19",
                          "available_ip": null,
                          "availability_zone": "us-east-1d",
                          "tags": {},
                          "provider_data": null,
                          "adapter_id": "906b379e-3f78-4ade-bc64-587a8919bd78",
                          "account_id": "e7028ea2-4bd7-428b-b538-1bf0bfb4cb76",
                          "region_id": "720368ad-d9f2-4bd8-8cf5-01f9c21a6807",
                          "state": "pending",
                          "data": null
                        },
                        {
                          "id": "acca5944-496f-4ae5-ab34-30e2e736f122",
                          "provider_id": null,
                          "provider_vpc_id": null,
                          "name": "SN-V20-$$servername-##",
                          "vpc_id": "16ef4f23-56e2-4f45-a2e3-f20e907bbfcf",
                          "cidr_block": "10.0.224.0/19",
                          "available_ip": null,
                          "availability_zone": "us-east-1b",
                          "tags": {},
                          "provider_data": null,
                          "adapter_id": "906b379e-3f78-4ade-bc64-587a8919bd78",
                          "account_id": "e7028ea2-4bd7-428b-b538-1bf0bfb4cb76",
                          "region_id": "720368ad-d9f2-4bd8-8cf5-01f9c21a6807",
                          "state": "pending",
                          "data": null
                        }
                      ]
                    },
                    "title": "Subnet Service Ids"
                  },
                  {
                    "name": "vpc_id",
                    "value": "16ef4f23-56e2-4f45-a2e3-f20e907bbfcf",
                    "form_options": {
                      "type": "hidden"
                    },
                    "title": "VPC Id"
                  }
                ]
              },
              "existing_subnet_group": true,
              "properties": [
                {
                  "name": "description",
                  "value": "Subnet Group Description",
                  "form_options": {
                    "type": "text"
                  },
                  "title": "Description"
                },
                {
                  "name": "uniq_provider_id",
                  "value": "14823920367535486",
                  "form_options": {
                    "type": "hidden"
                  },
                  "title": "Uniq Provider Id"
                },
                {
                  "name": "provider_id",
                  "form_options": {
                    "type": "hidden"
                  },
                  "title": "Provider Id"
                },
                {
                  "name": "subnet_ids",
                  "value": [],
                  "form_options": {
                    "type": "array",
                    "options": null
                  },
                  "title": "Subnet Ids"
                },
                {
                  "name": "subnet_service_ids",
                  "value": [
                    "0af16444-1472-4db3-9a53-8c4707e4f2b6",
                    "f167f871-edca-47cc-b2c6-9e168f152c77"
                  ],
                  "form_options": {
                    "type": "array",
                    "options": [
                      {
                        "id": "e395e5df-df3c-4980-b5e1-553416679ddd",
                        "provider_id": null,
                        "provider_vpc_id": null,
                        "name": "SN-V20-$$servername-##",
                        "vpc_id": "16ef4f23-56e2-4f45-a2e3-f20e907bbfcf",
                        "cidr_block": "10.0.192.0/19",
                        "available_ip": null,
                        "availability_zone": "us-east-1d",
                        "tags": {},
                        "provider_data": null,
                        "adapter_id": "906b379e-3f78-4ade-bc64-587a8919bd78",
                        "account_id": "e7028ea2-4bd7-428b-b538-1bf0bfb4cb76",
                        "region_id": "720368ad-d9f2-4bd8-8cf5-01f9c21a6807",
                        "state": "pending",
                        "data": null
                      },
                      {
                        "id": "acca5944-496f-4ae5-ab34-30e2e736f122",
                        "provider_id": null,
                        "provider_vpc_id": null,
                        "name": "SN-V20-$$servername-##",
                        "vpc_id": "16ef4f23-56e2-4f45-a2e3-f20e907bbfcf",
                        "cidr_block": "10.0.224.0/19",
                        "available_ip": null,
                        "availability_zone": "us-east-1b",
                        "tags": {},
                        "provider_data": null,
                        "adapter_id": "906b379e-3f78-4ade-bc64-587a8919bd78",
                        "account_id": "e7028ea2-4bd7-428b-b538-1bf0bfb4cb76",
                        "region_id": "720368ad-d9f2-4bd8-8cf5-01f9c21a6807",
                        "state": "pending",
                        "data": null
                      }
                    ]
                  },
                  "title": "Subnet Service Ids"
                },
                {
                  "name": "vpc_id",
                  "value": "16ef4f23-56e2-4f45-a2e3-f20e907bbfcf",
                  "form_options": {
                    "type": "hidden"
                  },
                  "title": "VPC Id"
                }
              ],
              "interfaces": [
                {
                  "id": "f5fe0748-61fa-4fa1-b254-acf961440146",
                  "name": "subnet-group",
                  "interface_type": "Protocols::SubnetGroup",
                  "depends": false,
                  "connections": []
                },
                {
                  "id": "3e2a9303-c313-4bb2-bee8-4e98bae6df06",
                  "name": "prat",
                  "interface_type": "Protocols::Vpc",
                  "depends": true,
                  "connections": [
                    {
                      "id": "0ca6b222-0102-4ed0-b7b0-9b8c9d27a79f",
                      "interface_id": "3e2a9303-c313-4bb2-bee8-4e98bae6df06",
                      "remote_interface_id": "cbb835cb-991c-4b69-a081-195c597025bf",
                      "internal": false
                    }
                  ]
                },
                {
                  "id": "02172b1b-618d-4b20-83b8-666202534e60",
                  "name": "SN-V20-$$servername-##",
                  "interface_type": "Protocols::Subnet",
                  "depends": true,
                  "connections": [
                    {
                      "id": "45aaef85-9a53-42f2-b331-3dc9ed03751e",
                      "interface_id": "02172b1b-618d-4b20-83b8-666202534e60",
                      "remote_interface_id": "b2c454b8-ea36-48d2-9b11-140cc3b1a4a4",
                      "internal": false
                    },
                    {
                      "id": "3c2d31fd-5f06-43e4-802b-bddb20096bad",
                      "interface_id": "02172b1b-618d-4b20-83b8-666202534e60",
                      "remote_interface_id": "00a5f387-643e-46bb-949c-3062b5bfebec",
                      "internal": false
                    }
                  ]
                }
              ],
              "provides": [
                {
                  "name": "subnet",
                  "type": "Protocols::SubnetGroup",
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
              "id": "2854ada8-2888-4c66-9a64-3047c05dc732",
              "name": "$$serviceowner-DB##-V20",
              "type": "Services::Database::Rds::AWS",
              "geometry": {
                "x": "67.28",
                "y": "60",
                "width": "56",
                "height": "56"
              },
              "additional_properties": {
                "id": "2854ada8-2888-4c66-9a64-3047c05dc732",
                "numbered": false,
                "edge": false,
                "service_type": "db",
                "generic_type": "Services::Database::Rds",
                "type": "Services::Database::Rds::AWS",
                "name": "$$serviceowner-DB##-V20",
                "drawable": true,
                "draggable": true,
                "interfaces": [
                  {
                    "id": "61d095ae-5b9b-4fc5-8ab8-ef7f06c3c5f4",
                    "name": "default",
                    "depends": true,
                    "connections": [
                      {
                        "id": "d7e4aa6e-e6a9-4a83-bb47-0b5aba007f8d",
                        "interface_id": "61d095ae-5b9b-4fc5-8ab8-ef7f06c3c5f4",
                        "remote_interface_id": "94c76455-6f91-4128-8db1-d90b323cf891",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::SecurityGroup"
                  },
                  {
                    "id": "7bf5ca9d-cd73-402d-839d-aeae9101ec4a",
                    "name": "mysql",
                    "depends": false,
                    "connections": null,
                    "interface_type": "Protocols::Rds"
                  },
                  {
                    "id": "cb7be7e8-d265-475d-a608-dcbdb6a6e669",
                    "name": "sng-v20-$$serviceowner-$$nameenvironment##",
                    "depends": true,
                    "connections": [
                      {
                        "id": "826fddc5-623d-43e9-954f-128f35613e7a",
                        "interface_id": "cb7be7e8-d265-475d-a608-dcbdb6a6e669",
                        "remote_interface_id": "f5fe0748-61fa-4fa1-b254-acf961440146",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::SubnetGroup"
                  },
                  {
                    "id": "94c0db1a-40a1-4060-874a-f3c581d43f1f",
                    "name": "prat",
                    "depends": true,
                    "connections": [
                      {
                        "id": "4272a50d-6f61-4ec5-9f64-ea8155ddba16",
                        "interface_id": "94c0db1a-40a1-4060-874a-f3c581d43f1f",
                        "remote_interface_id": "cbb835cb-991c-4b69-a081-195c597025bf",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::Vpc"
                  }
                ],
                "vpc_id": "16ef4f23-56e2-4f45-a2e3-f20e907bbfcf",
                "primary_key": "",
                "provides": [
                  {
                    "name": "rds",
                    "type": "Protocols::Rds",
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
                "parent_id": "a295c0a9-6d95-4d9d-b4d5-58cf2b85ea36",
                "properties": [
                  {
                    "name": "multi_az",
                    "value": false,
                    "form_options": {
                      "type": "checkbox"
                    },
                    "title": "Multi AZ"
                  },
                  {
                    "name": "allocated_storage",
                    "value": "5",
                    "form_options": {
                      "max": "3072",
                      "min": "5",
                      "step": "1",
                      "type": "range"
                    },
                    "title": "Allocated Storage"
                  },
                  {
                    "name": "storage_type",
                    "value": "gp2",
                    "form_options": {
                      "type": "select",
                      "options": {
                        "gp2": "General Purpose (SSD)",
                        "standard": "Magnetic",
                        "io1": "Provisioned IOPS (SSD)"
                      }
                    },
                    "title": "Storage Type"
                  },
                  {
                    "name": "db_name",
                    "value": "2222222",
                    "form_options": {
                      "type": "text"
                    },
                    "title": "Database Name"
                  },
                  {
                    "name": "endpoint_address",
                    "form_options": {
                      "type": "hidden"
                    },
                    "title": "DB Endpoint Address"
                  },
                  {
                    "name": "engine",
                    "value": "mysql",
                    "form_options": {
                      "type": "hidden"
                    },
                    "title": "DB Engine"
                  },
                  {
                    "name": "license_model",
                    "value": "general-public-license",
                    "form_options": {
                      "type": "select",
                      "options": [
                        "general-public-license"
                      ]
                    },
                    "title": "License Model"
                  },
                  {
                    "name": "engine_version",
                    "value": "5.1.73a",
                    "form_options": {
                      "type": "select",
                      "options": [
                        "5.1.73a",
                        "5.1.73b",
                        "5.5.40a",
                        "5.5.40b",
                        "5.5.41",
                        "5.5.42",
                        "5.6.19a",
                        "5.6.19b",
                        "5.6.21",
                        "5.6.21b",
                        "5.6.22",
                        "5.6.23",
                        "5.6.27",
                        "5.6.29",
                        "5.7.10",
                        "5.7.11"
                      ]
                    },
                    "title": "Engine Version"
                  },
                  {
                    "name": "flavor_id",
                    "value": "db.m1.large",
                    "form_options": {
                      "type": "select",
                      "options": {
                        "5.7.11": [
                          "db.m1.large",
                          "db.m1.medium",
                          "db.m1.small",
                          "db.m1.xlarge",
                          "db.m2.2xlarge",
                          "db.m2.4xlarge",
                          "db.m2.xlarge",
                          "db.m3.2xlarge",
                          "db.m3.large",
                          "db.m3.medium",
                          "db.m3.xlarge",
                          "db.m4.10xlarge",
                          "db.m4.2xlarge",
                          "db.m4.4xlarge",
                          "db.m4.large",
                          "db.m4.xlarge",
                          "db.r3.2xlarge",
                          "db.r3.4xlarge",
                          "db.r3.8xlarge",
                          "db.r3.large",
                          "db.r3.xlarge",
                          "db.t1.micro",
                          "db.t2.large",
                          "db.t2.medium",
                          "db.t2.micro",
                          "db.t2.small"
                        ],
                        "5.7.10": [
                          "db.m1.large",
                          "db.m1.medium",
                          "db.m1.small",
                          "db.m1.xlarge",
                          "db.m2.2xlarge",
                          "db.m2.4xlarge",
                          "db.m2.xlarge",
                          "db.m3.2xlarge",
                          "db.m3.large",
                          "db.m3.medium",
                          "db.m3.xlarge",
                          "db.m4.10xlarge",
                          "db.m4.2xlarge",
                          "db.m4.4xlarge",
                          "db.m4.large",
                          "db.m4.xlarge",
                          "db.r3.2xlarge",
                          "db.r3.4xlarge",
                          "db.r3.8xlarge",
                          "db.r3.large",
                          "db.r3.xlarge",
                          "db.t1.micro",
                          "db.t2.large",
                          "db.t2.medium",
                          "db.t2.micro",
                          "db.t2.small"
                        ],
                        "5.6.29": [
                          "db.m1.large",
                          "db.m1.medium",
                          "db.m1.small",
                          "db.m1.xlarge",
                          "db.m2.2xlarge",
                          "db.m2.4xlarge",
                          "db.m2.xlarge",
                          "db.m3.2xlarge",
                          "db.m3.large",
                          "db.m3.medium",
                          "db.m3.xlarge",
                          "db.m4.10xlarge",
                          "db.m4.2xlarge",
                          "db.m4.4xlarge",
                          "db.m4.large",
                          "db.m4.xlarge",
                          "db.r3.2xlarge",
                          "db.r3.4xlarge",
                          "db.r3.8xlarge",
                          "db.r3.large",
                          "db.r3.xlarge",
                          "db.t1.micro",
                          "db.t2.large",
                          "db.t2.medium",
                          "db.t2.micro",
                          "db.t2.small"
                        ],
                        "5.6.27": [
                          "db.m1.large",
                          "db.m1.medium",
                          "db.m1.small",
                          "db.m1.xlarge",
                          "db.m2.2xlarge",
                          "db.m2.4xlarge",
                          "db.m2.xlarge",
                          "db.m3.2xlarge",
                          "db.m3.large",
                          "db.m3.medium",
                          "db.m3.xlarge",
                          "db.m4.10xlarge",
                          "db.m4.2xlarge",
                          "db.m4.4xlarge",
                          "db.m4.large",
                          "db.m4.xlarge",
                          "db.r3.2xlarge",
                          "db.r3.4xlarge",
                          "db.r3.8xlarge",
                          "db.r3.large",
                          "db.r3.xlarge",
                          "db.t1.micro",
                          "db.t2.large",
                          "db.t2.medium",
                          "db.t2.micro",
                          "db.t2.small"
                        ],
                        "5.6.23": [
                          "db.m1.large",
                          "db.m1.medium",
                          "db.m1.small",
                          "db.m1.xlarge",
                          "db.m2.2xlarge",
                          "db.m2.4xlarge",
                          "db.m2.xlarge",
                          "db.m3.2xlarge",
                          "db.m3.large",
                          "db.m3.medium",
                          "db.m3.xlarge",
                          "db.m4.10xlarge",
                          "db.m4.2xlarge",
                          "db.m4.4xlarge",
                          "db.m4.large",
                          "db.m4.xlarge",
                          "db.r3.2xlarge",
                          "db.r3.4xlarge",
                          "db.r3.8xlarge",
                          "db.r3.large",
                          "db.r3.xlarge",
                          "db.t1.micro",
                          "db.t2.large",
                          "db.t2.medium",
                          "db.t2.micro",
                          "db.t2.small"
                        ],
                        "5.6.22": [
                          "db.m1.large",
                          "db.m1.medium",
                          "db.m1.small",
                          "db.m1.xlarge",
                          "db.m2.2xlarge",
                          "db.m2.4xlarge",
                          "db.m2.xlarge",
                          "db.m3.2xlarge",
                          "db.m3.large",
                          "db.m3.medium",
                          "db.m3.xlarge",
                          "db.m4.10xlarge",
                          "db.m4.2xlarge",
                          "db.m4.4xlarge",
                          "db.m4.large",
                          "db.m4.xlarge",
                          "db.r3.2xlarge",
                          "db.r3.4xlarge",
                          "db.r3.8xlarge",
                          "db.r3.large",
                          "db.r3.xlarge",
                          "db.t1.micro",
                          "db.t2.large",
                          "db.t2.medium",
                          "db.t2.micro",
                          "db.t2.small"
                        ],
                        "5.6.21b": [
                          "db.m1.large",
                          "db.m1.medium",
                          "db.m1.small",
                          "db.m1.xlarge",
                          "db.m2.2xlarge",
                          "db.m2.4xlarge",
                          "db.m2.xlarge",
                          "db.m3.2xlarge",
                          "db.m3.large",
                          "db.m3.medium",
                          "db.m3.xlarge",
                          "db.m4.10xlarge",
                          "db.m4.2xlarge",
                          "db.m4.4xlarge",
                          "db.m4.large",
                          "db.m4.xlarge",
                          "db.r3.2xlarge",
                          "db.r3.4xlarge",
                          "db.r3.8xlarge",
                          "db.r3.large",
                          "db.r3.xlarge",
                          "db.t1.micro",
                          "db.t2.large",
                          "db.t2.medium",
                          "db.t2.micro",
                          "db.t2.small"
                        ],
                        "5.6.21": [
                          "db.m1.large",
                          "db.m1.medium",
                          "db.m1.small",
                          "db.m1.xlarge",
                          "db.m2.2xlarge",
                          "db.m2.4xlarge",
                          "db.m2.xlarge",
                          "db.m3.2xlarge",
                          "db.m3.large",
                          "db.m3.medium",
                          "db.m3.xlarge",
                          "db.m4.10xlarge",
                          "db.m4.2xlarge",
                          "db.m4.4xlarge",
                          "db.m4.large",
                          "db.m4.xlarge",
                          "db.r3.2xlarge",
                          "db.r3.4xlarge",
                          "db.r3.8xlarge",
                          "db.r3.large",
                          "db.r3.xlarge",
                          "db.t1.micro",
                          "db.t2.large",
                          "db.t2.medium",
                          "db.t2.micro",
                          "db.t2.small"
                        ],
                        "5.6.19b": [
                          "db.m1.large",
                          "db.m1.medium",
                          "db.m1.small",
                          "db.m1.xlarge",
                          "db.m2.2xlarge",
                          "db.m2.4xlarge",
                          "db.m2.xlarge",
                          "db.m3.2xlarge",
                          "db.m3.large",
                          "db.m3.medium",
                          "db.m3.xlarge",
                          "db.m4.10xlarge",
                          "db.m4.2xlarge",
                          "db.m4.4xlarge",
                          "db.m4.large",
                          "db.m4.xlarge",
                          "db.r3.2xlarge",
                          "db.r3.4xlarge",
                          "db.r3.8xlarge",
                          "db.r3.large",
                          "db.r3.xlarge",
                          "db.t1.micro",
                          "db.t2.large",
                          "db.t2.medium",
                          "db.t2.micro",
                          "db.t2.small"
                        ],
                        "5.6.19a": [
                          "db.m1.large",
                          "db.m1.medium",
                          "db.m1.small",
                          "db.m1.xlarge",
                          "db.m2.2xlarge",
                          "db.m2.4xlarge",
                          "db.m2.xlarge",
                          "db.m3.2xlarge",
                          "db.m3.large",
                          "db.m3.medium",
                          "db.m3.xlarge",
                          "db.m4.10xlarge",
                          "db.m4.2xlarge",
                          "db.m4.4xlarge",
                          "db.m4.large",
                          "db.m4.xlarge",
                          "db.r3.2xlarge",
                          "db.r3.4xlarge",
                          "db.r3.8xlarge",
                          "db.r3.large",
                          "db.r3.xlarge",
                          "db.t1.micro",
                          "db.t2.large",
                          "db.t2.medium",
                          "db.t2.micro",
                          "db.t2.small"
                        ],
                        "5.5.42": [
                          "db.m1.large",
                          "db.m1.medium",
                          "db.m1.small",
                          "db.m1.xlarge",
                          "db.m2.2xlarge",
                          "db.m2.4xlarge",
                          "db.m2.xlarge",
                          "db.m3.2xlarge",
                          "db.m3.large",
                          "db.m3.medium",
                          "db.m3.xlarge",
                          "db.m4.10xlarge",
                          "db.m4.2xlarge",
                          "db.m4.4xlarge",
                          "db.m4.large",
                          "db.m4.xlarge",
                          "db.r3.2xlarge",
                          "db.r3.4xlarge",
                          "db.r3.8xlarge",
                          "db.r3.large",
                          "db.r3.xlarge",
                          "db.t1.micro",
                          "db.t2.large",
                          "db.t2.medium",
                          "db.t2.micro",
                          "db.t2.small"
                        ],
                        "5.5.41": [
                          "db.m1.large",
                          "db.m1.medium",
                          "db.m1.small",
                          "db.m1.xlarge",
                          "db.m2.2xlarge",
                          "db.m2.4xlarge",
                          "db.m2.xlarge",
                          "db.m3.2xlarge",
                          "db.m3.large",
                          "db.m3.medium",
                          "db.m3.xlarge",
                          "db.m4.10xlarge",
                          "db.m4.2xlarge",
                          "db.m4.4xlarge",
                          "db.m4.large",
                          "db.m4.xlarge",
                          "db.r3.2xlarge",
                          "db.r3.4xlarge",
                          "db.r3.8xlarge",
                          "db.r3.large",
                          "db.r3.xlarge",
                          "db.t1.micro",
                          "db.t2.large",
                          "db.t2.medium",
                          "db.t2.micro",
                          "db.t2.small"
                        ],
                        "5.5.40b": [
                          "db.m1.large",
                          "db.m1.medium",
                          "db.m1.small",
                          "db.m1.xlarge",
                          "db.m2.2xlarge",
                          "db.m2.4xlarge",
                          "db.m2.xlarge",
                          "db.m3.2xlarge",
                          "db.m3.large",
                          "db.m3.medium",
                          "db.m3.xlarge",
                          "db.m4.10xlarge",
                          "db.m4.2xlarge",
                          "db.m4.4xlarge",
                          "db.m4.large",
                          "db.m4.xlarge",
                          "db.r3.2xlarge",
                          "db.r3.4xlarge",
                          "db.r3.8xlarge",
                          "db.r3.large",
                          "db.r3.xlarge",
                          "db.t1.micro",
                          "db.t2.large",
                          "db.t2.medium",
                          "db.t2.micro",
                          "db.t2.small"
                        ],
                        "5.5.40a": [
                          "db.m1.large",
                          "db.m1.medium",
                          "db.m1.small",
                          "db.m1.xlarge",
                          "db.m2.2xlarge",
                          "db.m2.4xlarge",
                          "db.m2.xlarge",
                          "db.m3.2xlarge",
                          "db.m3.large",
                          "db.m3.medium",
                          "db.m3.xlarge",
                          "db.m4.10xlarge",
                          "db.m4.2xlarge",
                          "db.m4.4xlarge",
                          "db.m4.large",
                          "db.m4.xlarge",
                          "db.r3.2xlarge",
                          "db.r3.4xlarge",
                          "db.r3.8xlarge",
                          "db.r3.large",
                          "db.r3.xlarge",
                          "db.t1.micro",
                          "db.t2.large",
                          "db.t2.medium",
                          "db.t2.micro",
                          "db.t2.small"
                        ],
                        "5.1.73b": [
                          "db.m1.large",
                          "db.m1.medium",
                          "db.m1.small",
                          "db.m1.xlarge",
                          "db.m2.2xlarge",
                          "db.m2.4xlarge",
                          "db.m2.xlarge",
                          "db.m3.2xlarge",
                          "db.m3.large",
                          "db.m3.medium",
                          "db.m3.xlarge",
                          "db.t1.micro"
                        ],
                        "5.1.73a": [
                          "db.m1.large",
                          "db.m1.medium",
                          "db.m1.small",
                          "db.m1.xlarge",
                          "db.m2.2xlarge",
                          "db.m2.4xlarge",
                          "db.m2.xlarge",
                          "db.m3.2xlarge",
                          "db.m3.large",
                          "db.m3.medium",
                          "db.m3.xlarge",
                          "db.t1.micro"
                        ]
                      }
                    },
                    "title": "Instance Class"
                  },
                  {
                    "name": "iops",
                    "value": "1000",
                    "form_options": {
                      "type": "text",
                      "depends_on": false
                    },
                    "title": "IOPS"
                  },
                  {
                    "name": "publicly_accessible",
                    "value": false,
                    "form_options": {
                      "type": "checkbox"
                    },
                    "title": "Publicly Accessible"
                  },
                  {
                    "name": "availability_zone",
                    "value": "us-east-1b",
                    "form_options": {
                      "type": "select",
                      "options": [],
                      "depends_on": false
                    },
                    "title": "Availability Zone"
                  },
                  {
                    "name": "backup_retention_period",
                    "value": "1",
                    "form_options": {
                      "max": "35",
                      "min": "0",
                      "step": "1",
                      "type": "range"
                    },
                    "title": "Backup Retension Period"
                  },
                  {
                    "name": "master_username",
                    "value": "l333333lj",
                    "form_options": {
                      "type": "text"
                    },
                    "title": "Master Username"
                  },
                  {
                    "name": "password",
                    "value": "l3333333",
                    "form_options": {
                      "type": "password"
                    },
                    "title": "Master Password"
                  },
                  {
                    "name": "port",
                    "value": "3306",
                    "form_options": {
                      "type": "text"
                    },
                    "title": "Database Port"
                  },
                  {
                    "name": "backup_window",
                    "value": "No Preference",
                    "form_options": {
                      "type": "select",
                      "options": [
                        "Select Window",
                        "No Preference"
                      ]
                    },
                    "title": "Backup Window"
                  },
                  {
                    "name": "preferred_backup_window_hour",
                    "value": "0",
                    "form_options": {
                      "type": "select",
                      "options": [
                        "0",
                        "1",
                        "2",
                        "3",
                        "4",
                        "5",
                        "6",
                        "7",
                        "8",
                        "9",
                        "10",
                        "11",
                        "12",
                        "13",
                        "14",
                        "15",
                        "16",
                        "17",
                        "18",
                        "19",
                        "20",
                        "21",
                        "22",
                        "23"
                      ],
                      "related_options_name": "preferred_backup_window_minute",
                      "related_options": [
                        "0",
                        "1",
                        "2",
                        "3",
                        "4",
                        "5",
                        "6",
                        "7",
                        "8",
                        "9",
                        "10",
                        "11",
                        "12",
                        "13",
                        "14",
                        "15",
                        "16",
                        "17",
                        "18",
                        "19",
                        "20",
                        "21",
                        "22",
                        "23",
                        "24",
                        "25",
                        "26",
                        "27",
                        "28",
                        "29",
                        "30",
                        "31",
                        "32",
                        "33",
                        "34",
                        "35",
                        "36",
                        "37",
                        "38",
                        "39",
                        "40",
                        "41",
                        "42",
                        "43",
                        "44",
                        "45",
                        "46",
                        "47",
                        "48",
                        "49",
                        "50",
                        "51",
                        "52",
                        "53",
                        "54",
                        "55",
                        "56",
                        "57",
                        "58",
                        "59"
                      ],
                      "related_value": "0",
                      "unitLabel": "UTC",
                      "depends_on": false
                    },
                    "title": "Start Time"
                  },
                  {
                    "name": "preferred_backup_window_duration",
                    "value": "0.5",
                    "form_options": {
                      "type": "select",
                      "options": [
                        "0.5",
                        "1",
                        "1.5",
                        "2",
                        "2.5",
                        "3"
                      ],
                      "unitLabel": "Hours",
                      "depends_on": false
                    },
                    "title": "Duration"
                  },
                  {
                    "name": "auto_minor_version_upgrade",
                    "value": true,
                    "form_options": {
                      "type": "checkbox"
                    },
                    "title": "Auto Minor Version Upgrade"
                  },
                  {
                    "name": "maintenance_window",
                    "value": "No Preference",
                    "form_options": {
                      "type": "select",
                      "options": [
                        "Select Window",
                        "No Preference"
                      ]
                    },
                    "title": "Maintenance Window"
                  },
                  {
                    "name": "preferred_maintenance_window_hour",
                    "value": "0",
                    "form_options": {
                      "type": "select",
                      "options": [
                        "0",
                        "1",
                        "2",
                        "3",
                        "4",
                        "5",
                        "6",
                        "7",
                        "8",
                        "9",
                        "10",
                        "11",
                        "12",
                        "13",
                        "14",
                        "15",
                        "16",
                        "17",
                        "18",
                        "19",
                        "20",
                        "21",
                        "22",
                        "23"
                      ],
                      "related_options_name": "preferred_maintenance_window_minute",
                      "related_options": [
                        "0",
                        "1",
                        "2",
                        "3",
                        "4",
                        "5",
                        "6",
                        "7",
                        "8",
                        "9",
                        "10",
                        "11",
                        "12",
                        "13",
                        "14",
                        "15",
                        "16",
                        "17",
                        "18",
                        "19",
                        "20",
                        "21",
                        "22",
                        "23",
                        "24",
                        "25",
                        "26",
                        "27",
                        "28",
                        "29",
                        "30",
                        "31",
                        "32",
                        "33",
                        "34",
                        "35",
                        "36",
                        "37",
                        "38",
                        "39",
                        "40",
                        "41",
                        "42",
                        "43",
                        "44",
                        "45",
                        "46",
                        "47",
                        "48",
                        "49",
                        "50",
                        "51",
                        "52",
                        "53",
                        "54",
                        "55",
                        "56",
                        "57",
                        "58",
                        "59"
                      ],
                      "related_value": "0",
                      "unitLabel": "UTC",
                      "depends_on": false
                    },
                    "title": "Start Time"
                  },
                  {
                    "name": "preferred_maintenance_window_duration",
                    "value": "0.5",
                    "form_options": {
                      "type": "select",
                      "options": [
                        "0.5",
                        "1",
                        "1.5",
                        "2",
                        "2.5",
                        "3"
                      ],
                      "unitLabel": "Hours",
                      "depends_on": false
                    },
                    "title": "Duration"
                  },
                  {
                    "name": "preferred_maintenance_window_day",
                    "value": "mon",
                    "form_options": {
                      "type": "select",
                      "options": {
                        "mon": "Monday",
                        "tue": "Tuesday",
                        "wed": "Wednesday",
                        "thu": "Thursday",
                        "fri": "Friday",
                        "sat": "Saturday",
                        "sun": "Sunday"
                      },
                      "depends_on": false
                    },
                    "title": "Start Day"
                  },
                  {
                    "name": "snapshot_id",
                    "value": "",
                    "form_options": {
                      "type": "select",
                      "options": [
                        "37c1c601962a4b5379e4d0d82942d822"
                      ]
                    },
                    "title": "Type"
                  },
                  {
                    "name": "snapshot_environment_id",
                    "value": "",
                    "form_options": {
                      "type": "select",
                      "options": [
                        "4721417fa1d1884c6213b04f3ccbde7d"
                      ]
                    },
                    "title": "Type"
                  },
                  {
                    "name": "create_from_snapshot",
                    "value": false,
                    "form_options": {
                      "environment_with_snapshots_exists": false,
                      "unallocated_snapshots_exists": false,
                      "type": "checkbox"
                    },
                    "title": "Create From Snapshot"
                  },
                  {
                    "name": "snapshot_id",
                    "value": "",
                    "form_options": {
                      "type": "select",
                      "options": []
                    },
                    "title": "Snapshot ID"
                  },
                  {
                    "name": "snapshot_provider_id",
                    "value": "",
                    "form_options": {
                      "type": "hidden",
                      "readonly": "readonly"
                    },
                    "title": "Snapshot Provider ID"
                  },
                  {
                    "name": "snapshot_environment_id",
                    "value": "",
                    "form_options": {
                      "type": "select",
                      "options": []
                    },
                    "title": "Snapshot Environment ID"
                  }
                ]
              },
              "properties": [
                {
                  "name": "multi_az",
                  "value": false,
                  "form_options": {
                    "type": "checkbox"
                  },
                  "title": "Multi AZ"
                },
                {
                  "name": "allocated_storage",
                  "value": "5",
                  "form_options": {
                    "max": "3072",
                    "min": "5",
                    "step": "1",
                    "type": "range"
                  },
                  "title": "Allocated Storage"
                },
                {
                  "name": "storage_type",
                  "value": "gp2",
                  "form_options": {
                    "type": "select",
                    "options": {
                      "gp2": "General Purpose (SSD)",
                      "standard": "Magnetic",
                      "io1": "Provisioned IOPS (SSD)"
                    }
                  },
                  "title": "Storage Type"
                },
                {
                  "name": "db_name",
                  "value": "2222222",
                  "form_options": {
                    "type": "text"
                  },
                  "title": "Database Name"
                },
                {
                  "name": "endpoint_address",
                  "form_options": {
                    "type": "hidden"
                  },
                  "title": "DB Endpoint Address"
                },
                {
                  "name": "engine",
                  "value": "mysql",
                  "form_options": {
                    "type": "hidden"
                  },
                  "title": "DB Engine"
                },
                {
                  "name": "license_model",
                  "value": "general-public-license",
                  "form_options": {
                    "type": "select",
                    "options": [
                      "general-public-license"
                    ]
                  },
                  "title": "License Model"
                },
                {
                  "name": "engine_version",
                  "value": "5.1.73a",
                  "form_options": {
                    "type": "select",
                    "options": [
                      "5.1.73a",
                      "5.1.73b",
                      "5.5.40a",
                      "5.5.40b",
                      "5.5.41",
                      "5.5.42",
                      "5.6.19a",
                      "5.6.19b",
                      "5.6.21",
                      "5.6.21b",
                      "5.6.22",
                      "5.6.23",
                      "5.6.27",
                      "5.6.29",
                      "5.7.10",
                      "5.7.11"
                    ]
                  },
                  "title": "Engine Version"
                },
                {
                  "name": "flavor_id",
                  "value": "db.m1.large",
                  "form_options": {
                    "type": "select",
                    "options": {
                      "5.7.11": [
                        "db.m1.large",
                        "db.m1.medium",
                        "db.m1.small",
                        "db.m1.xlarge",
                        "db.m2.2xlarge",
                        "db.m2.4xlarge",
                        "db.m2.xlarge",
                        "db.m3.2xlarge",
                        "db.m3.large",
                        "db.m3.medium",
                        "db.m3.xlarge",
                        "db.m4.10xlarge",
                        "db.m4.2xlarge",
                        "db.m4.4xlarge",
                        "db.m4.large",
                        "db.m4.xlarge",
                        "db.r3.2xlarge",
                        "db.r3.4xlarge",
                        "db.r3.8xlarge",
                        "db.r3.large",
                        "db.r3.xlarge",
                        "db.t1.micro",
                        "db.t2.large",
                        "db.t2.medium",
                        "db.t2.micro",
                        "db.t2.small"
                      ],
                      "5.7.10": [
                        "db.m1.large",
                        "db.m1.medium",
                        "db.m1.small",
                        "db.m1.xlarge",
                        "db.m2.2xlarge",
                        "db.m2.4xlarge",
                        "db.m2.xlarge",
                        "db.m3.2xlarge",
                        "db.m3.large",
                        "db.m3.medium",
                        "db.m3.xlarge",
                        "db.m4.10xlarge",
                        "db.m4.2xlarge",
                        "db.m4.4xlarge",
                        "db.m4.large",
                        "db.m4.xlarge",
                        "db.r3.2xlarge",
                        "db.r3.4xlarge",
                        "db.r3.8xlarge",
                        "db.r3.large",
                        "db.r3.xlarge",
                        "db.t1.micro",
                        "db.t2.large",
                        "db.t2.medium",
                        "db.t2.micro",
                        "db.t2.small"
                      ],
                      "5.6.29": [
                        "db.m1.large",
                        "db.m1.medium",
                        "db.m1.small",
                        "db.m1.xlarge",
                        "db.m2.2xlarge",
                        "db.m2.4xlarge",
                        "db.m2.xlarge",
                        "db.m3.2xlarge",
                        "db.m3.large",
                        "db.m3.medium",
                        "db.m3.xlarge",
                        "db.m4.10xlarge",
                        "db.m4.2xlarge",
                        "db.m4.4xlarge",
                        "db.m4.large",
                        "db.m4.xlarge",
                        "db.r3.2xlarge",
                        "db.r3.4xlarge",
                        "db.r3.8xlarge",
                        "db.r3.large",
                        "db.r3.xlarge",
                        "db.t1.micro",
                        "db.t2.large",
                        "db.t2.medium",
                        "db.t2.micro",
                        "db.t2.small"
                      ],
                      "5.6.27": [
                        "db.m1.large",
                        "db.m1.medium",
                        "db.m1.small",
                        "db.m1.xlarge",
                        "db.m2.2xlarge",
                        "db.m2.4xlarge",
                        "db.m2.xlarge",
                        "db.m3.2xlarge",
                        "db.m3.large",
                        "db.m3.medium",
                        "db.m3.xlarge",
                        "db.m4.10xlarge",
                        "db.m4.2xlarge",
                        "db.m4.4xlarge",
                        "db.m4.large",
                        "db.m4.xlarge",
                        "db.r3.2xlarge",
                        "db.r3.4xlarge",
                        "db.r3.8xlarge",
                        "db.r3.large",
                        "db.r3.xlarge",
                        "db.t1.micro",
                        "db.t2.large",
                        "db.t2.medium",
                        "db.t2.micro",
                        "db.t2.small"
                      ],
                      "5.6.23": [
                        "db.m1.large",
                        "db.m1.medium",
                        "db.m1.small",
                        "db.m1.xlarge",
                        "db.m2.2xlarge",
                        "db.m2.4xlarge",
                        "db.m2.xlarge",
                        "db.m3.2xlarge",
                        "db.m3.large",
                        "db.m3.medium",
                        "db.m3.xlarge",
                        "db.m4.10xlarge",
                        "db.m4.2xlarge",
                        "db.m4.4xlarge",
                        "db.m4.large",
                        "db.m4.xlarge",
                        "db.r3.2xlarge",
                        "db.r3.4xlarge",
                        "db.r3.8xlarge",
                        "db.r3.large",
                        "db.r3.xlarge",
                        "db.t1.micro",
                        "db.t2.large",
                        "db.t2.medium",
                        "db.t2.micro",
                        "db.t2.small"
                      ],
                      "5.6.22": [
                        "db.m1.large",
                        "db.m1.medium",
                        "db.m1.small",
                        "db.m1.xlarge",
                        "db.m2.2xlarge",
                        "db.m2.4xlarge",
                        "db.m2.xlarge",
                        "db.m3.2xlarge",
                        "db.m3.large",
                        "db.m3.medium",
                        "db.m3.xlarge",
                        "db.m4.10xlarge",
                        "db.m4.2xlarge",
                        "db.m4.4xlarge",
                        "db.m4.large",
                        "db.m4.xlarge",
                        "db.r3.2xlarge",
                        "db.r3.4xlarge",
                        "db.r3.8xlarge",
                        "db.r3.large",
                        "db.r3.xlarge",
                        "db.t1.micro",
                        "db.t2.large",
                        "db.t2.medium",
                        "db.t2.micro",
                        "db.t2.small"
                      ],
                      "5.6.21b": [
                        "db.m1.large",
                        "db.m1.medium",
                        "db.m1.small",
                        "db.m1.xlarge",
                        "db.m2.2xlarge",
                        "db.m2.4xlarge",
                        "db.m2.xlarge",
                        "db.m3.2xlarge",
                        "db.m3.large",
                        "db.m3.medium",
                        "db.m3.xlarge",
                        "db.m4.10xlarge",
                        "db.m4.2xlarge",
                        "db.m4.4xlarge",
                        "db.m4.large",
                        "db.m4.xlarge",
                        "db.r3.2xlarge",
                        "db.r3.4xlarge",
                        "db.r3.8xlarge",
                        "db.r3.large",
                        "db.r3.xlarge",
                        "db.t1.micro",
                        "db.t2.large",
                        "db.t2.medium",
                        "db.t2.micro",
                        "db.t2.small"
                      ],
                      "5.6.21": [
                        "db.m1.large",
                        "db.m1.medium",
                        "db.m1.small",
                        "db.m1.xlarge",
                        "db.m2.2xlarge",
                        "db.m2.4xlarge",
                        "db.m2.xlarge",
                        "db.m3.2xlarge",
                        "db.m3.large",
                        "db.m3.medium",
                        "db.m3.xlarge",
                        "db.m4.10xlarge",
                        "db.m4.2xlarge",
                        "db.m4.4xlarge",
                        "db.m4.large",
                        "db.m4.xlarge",
                        "db.r3.2xlarge",
                        "db.r3.4xlarge",
                        "db.r3.8xlarge",
                        "db.r3.large",
                        "db.r3.xlarge",
                        "db.t1.micro",
                        "db.t2.large",
                        "db.t2.medium",
                        "db.t2.micro",
                        "db.t2.small"
                      ],
                      "5.6.19b": [
                        "db.m1.large",
                        "db.m1.medium",
                        "db.m1.small",
                        "db.m1.xlarge",
                        "db.m2.2xlarge",
                        "db.m2.4xlarge",
                        "db.m2.xlarge",
                        "db.m3.2xlarge",
                        "db.m3.large",
                        "db.m3.medium",
                        "db.m3.xlarge",
                        "db.m4.10xlarge",
                        "db.m4.2xlarge",
                        "db.m4.4xlarge",
                        "db.m4.large",
                        "db.m4.xlarge",
                        "db.r3.2xlarge",
                        "db.r3.4xlarge",
                        "db.r3.8xlarge",
                        "db.r3.large",
                        "db.r3.xlarge",
                        "db.t1.micro",
                        "db.t2.large",
                        "db.t2.medium",
                        "db.t2.micro",
                        "db.t2.small"
                      ],
                      "5.6.19a": [
                        "db.m1.large",
                        "db.m1.medium",
                        "db.m1.small",
                        "db.m1.xlarge",
                        "db.m2.2xlarge",
                        "db.m2.4xlarge",
                        "db.m2.xlarge",
                        "db.m3.2xlarge",
                        "db.m3.large",
                        "db.m3.medium",
                        "db.m3.xlarge",
                        "db.m4.10xlarge",
                        "db.m4.2xlarge",
                        "db.m4.4xlarge",
                        "db.m4.large",
                        "db.m4.xlarge",
                        "db.r3.2xlarge",
                        "db.r3.4xlarge",
                        "db.r3.8xlarge",
                        "db.r3.large",
                        "db.r3.xlarge",
                        "db.t1.micro",
                        "db.t2.large",
                        "db.t2.medium",
                        "db.t2.micro",
                        "db.t2.small"
                      ],
                      "5.5.42": [
                        "db.m1.large",
                        "db.m1.medium",
                        "db.m1.small",
                        "db.m1.xlarge",
                        "db.m2.2xlarge",
                        "db.m2.4xlarge",
                        "db.m2.xlarge",
                        "db.m3.2xlarge",
                        "db.m3.large",
                        "db.m3.medium",
                        "db.m3.xlarge",
                        "db.m4.10xlarge",
                        "db.m4.2xlarge",
                        "db.m4.4xlarge",
                        "db.m4.large",
                        "db.m4.xlarge",
                        "db.r3.2xlarge",
                        "db.r3.4xlarge",
                        "db.r3.8xlarge",
                        "db.r3.large",
                        "db.r3.xlarge",
                        "db.t1.micro",
                        "db.t2.large",
                        "db.t2.medium",
                        "db.t2.micro",
                        "db.t2.small"
                      ],
                      "5.5.41": [
                        "db.m1.large",
                        "db.m1.medium",
                        "db.m1.small",
                        "db.m1.xlarge",
                        "db.m2.2xlarge",
                        "db.m2.4xlarge",
                        "db.m2.xlarge",
                        "db.m3.2xlarge",
                        "db.m3.large",
                        "db.m3.medium",
                        "db.m3.xlarge",
                        "db.m4.10xlarge",
                        "db.m4.2xlarge",
                        "db.m4.4xlarge",
                        "db.m4.large",
                        "db.m4.xlarge",
                        "db.r3.2xlarge",
                        "db.r3.4xlarge",
                        "db.r3.8xlarge",
                        "db.r3.large",
                        "db.r3.xlarge",
                        "db.t1.micro",
                        "db.t2.large",
                        "db.t2.medium",
                        "db.t2.micro",
                        "db.t2.small"
                      ],
                      "5.5.40b": [
                        "db.m1.large",
                        "db.m1.medium",
                        "db.m1.small",
                        "db.m1.xlarge",
                        "db.m2.2xlarge",
                        "db.m2.4xlarge",
                        "db.m2.xlarge",
                        "db.m3.2xlarge",
                        "db.m3.large",
                        "db.m3.medium",
                        "db.m3.xlarge",
                        "db.m4.10xlarge",
                        "db.m4.2xlarge",
                        "db.m4.4xlarge",
                        "db.m4.large",
                        "db.m4.xlarge",
                        "db.r3.2xlarge",
                        "db.r3.4xlarge",
                        "db.r3.8xlarge",
                        "db.r3.large",
                        "db.r3.xlarge",
                        "db.t1.micro",
                        "db.t2.large",
                        "db.t2.medium",
                        "db.t2.micro",
                        "db.t2.small"
                      ],
                      "5.5.40a": [
                        "db.m1.large",
                        "db.m1.medium",
                        "db.m1.small",
                        "db.m1.xlarge",
                        "db.m2.2xlarge",
                        "db.m2.4xlarge",
                        "db.m2.xlarge",
                        "db.m3.2xlarge",
                        "db.m3.large",
                        "db.m3.medium",
                        "db.m3.xlarge",
                        "db.m4.10xlarge",
                        "db.m4.2xlarge",
                        "db.m4.4xlarge",
                        "db.m4.large",
                        "db.m4.xlarge",
                        "db.r3.2xlarge",
                        "db.r3.4xlarge",
                        "db.r3.8xlarge",
                        "db.r3.large",
                        "db.r3.xlarge",
                        "db.t1.micro",
                        "db.t2.large",
                        "db.t2.medium",
                        "db.t2.micro",
                        "db.t2.small"
                      ],
                      "5.1.73b": [
                        "db.m1.large",
                        "db.m1.medium",
                        "db.m1.small",
                        "db.m1.xlarge",
                        "db.m2.2xlarge",
                        "db.m2.4xlarge",
                        "db.m2.xlarge",
                        "db.m3.2xlarge",
                        "db.m3.large",
                        "db.m3.medium",
                        "db.m3.xlarge",
                        "db.t1.micro"
                      ],
                      "5.1.73a": [
                        "db.m1.large",
                        "db.m1.medium",
                        "db.m1.small",
                        "db.m1.xlarge",
                        "db.m2.2xlarge",
                        "db.m2.4xlarge",
                        "db.m2.xlarge",
                        "db.m3.2xlarge",
                        "db.m3.large",
                        "db.m3.medium",
                        "db.m3.xlarge",
                        "db.t1.micro"
                      ]
                    }
                  },
                  "title": "Instance Class"
                },
                {
                  "name": "iops",
                  "value": "1000",
                  "form_options": {
                    "type": "text",
                    "depends_on": false
                  },
                  "title": "IOPS"
                },
                {
                  "name": "publicly_accessible",
                  "value": false,
                  "form_options": {
                    "type": "checkbox"
                  },
                  "title": "Publicly Accessible"
                },
                {
                  "name": "availability_zone",
                  "value": "us-east-1b",
                  "form_options": {
                    "type": "select",
                    "options": [],
                    "depends_on": false
                  },
                  "title": "Availability Zone"
                },
                {
                  "name": "backup_retention_period",
                  "value": "1",
                  "form_options": {
                    "max": "35",
                    "min": "0",
                    "step": "1",
                    "type": "range"
                  },
                  "title": "Backup Retension Period"
                },
                {
                  "name": "master_username",
                  "value": "l333333lj",
                  "form_options": {
                    "type": "text"
                  },
                  "title": "Master Username"
                },
                {
                  "name": "password",
                  "value": "l3333333",
                  "form_options": {
                    "type": "password"
                  },
                  "title": "Master Password"
                },
                {
                  "name": "port",
                  "value": 3306,
                  "form_options": {
                    "type": "text"
                  },
                  "title": "Database Port"
                },
                {
                  "name": "backup_window",
                  "value": "No Preference",
                  "form_options": {
                    "type": "select",
                    "options": [
                      "Select Window",
                      "No Preference"
                    ]
                  },
                  "title": "Backup Window"
                },
                {
                  "name": "preferred_backup_window_hour",
                  "value": "0",
                  "form_options": {
                    "type": "select",
                    "options": [
                      "0",
                      "1",
                      "2",
                      "3",
                      "4",
                      "5",
                      "6",
                      "7",
                      "8",
                      "9",
                      "10",
                      "11",
                      "12",
                      "13",
                      "14",
                      "15",
                      "16",
                      "17",
                      "18",
                      "19",
                      "20",
                      "21",
                      "22",
                      "23"
                    ],
                    "related_options_name": "preferred_backup_window_minute",
                    "related_options": [
                      "0",
                      "1",
                      "2",
                      "3",
                      "4",
                      "5",
                      "6",
                      "7",
                      "8",
                      "9",
                      "10",
                      "11",
                      "12",
                      "13",
                      "14",
                      "15",
                      "16",
                      "17",
                      "18",
                      "19",
                      "20",
                      "21",
                      "22",
                      "23",
                      "24",
                      "25",
                      "26",
                      "27",
                      "28",
                      "29",
                      "30",
                      "31",
                      "32",
                      "33",
                      "34",
                      "35",
                      "36",
                      "37",
                      "38",
                      "39",
                      "40",
                      "41",
                      "42",
                      "43",
                      "44",
                      "45",
                      "46",
                      "47",
                      "48",
                      "49",
                      "50",
                      "51",
                      "52",
                      "53",
                      "54",
                      "55",
                      "56",
                      "57",
                      "58",
                      "59"
                    ],
                    "related_value": "0",
                    "unitLabel": "UTC",
                    "depends_on": false
                  },
                  "title": "Start Time"
                },
                {
                  "name": "preferred_backup_window_duration",
                  "value": "0.5",
                  "form_options": {
                    "type": "select",
                    "options": [
                      "0.5",
                      "1",
                      "1.5",
                      "2",
                      "2.5",
                      "3"
                    ],
                    "unitLabel": "Hours",
                    "depends_on": false
                  },
                  "title": "Duration"
                },
                {
                  "name": "auto_minor_version_upgrade",
                  "value": true,
                  "form_options": {
                    "type": "checkbox"
                  },
                  "title": "Auto Minor Version Upgrade"
                },
                {
                  "name": "maintenance_window",
                  "value": "No Preference",
                  "form_options": {
                    "type": "select",
                    "options": [
                      "Select Window",
                      "No Preference"
                    ]
                  },
                  "title": "Maintenance Window"
                },
                {
                  "name": "preferred_maintenance_window_hour",
                  "value": "0",
                  "form_options": {
                    "type": "select",
                    "options": [
                      "0",
                      "1",
                      "2",
                      "3",
                      "4",
                      "5",
                      "6",
                      "7",
                      "8",
                      "9",
                      "10",
                      "11",
                      "12",
                      "13",
                      "14",
                      "15",
                      "16",
                      "17",
                      "18",
                      "19",
                      "20",
                      "21",
                      "22",
                      "23"
                    ],
                    "related_options_name": "preferred_maintenance_window_minute",
                    "related_options": [
                      "0",
                      "1",
                      "2",
                      "3",
                      "4",
                      "5",
                      "6",
                      "7",
                      "8",
                      "9",
                      "10",
                      "11",
                      "12",
                      "13",
                      "14",
                      "15",
                      "16",
                      "17",
                      "18",
                      "19",
                      "20",
                      "21",
                      "22",
                      "23",
                      "24",
                      "25",
                      "26",
                      "27",
                      "28",
                      "29",
                      "30",
                      "31",
                      "32",
                      "33",
                      "34",
                      "35",
                      "36",
                      "37",
                      "38",
                      "39",
                      "40",
                      "41",
                      "42",
                      "43",
                      "44",
                      "45",
                      "46",
                      "47",
                      "48",
                      "49",
                      "50",
                      "51",
                      "52",
                      "53",
                      "54",
                      "55",
                      "56",
                      "57",
                      "58",
                      "59"
                    ],
                    "related_value": "0",
                    "unitLabel": "UTC",
                    "depends_on": false
                  },
                  "title": "Start Time"
                },
                {
                  "name": "preferred_maintenance_window_duration",
                  "value": "0.5",
                  "form_options": {
                    "type": "select",
                    "options": [
                      "0.5",
                      "1",
                      "1.5",
                      "2",
                      "2.5",
                      "3"
                    ],
                    "unitLabel": "Hours",
                    "depends_on": false
                  },
                  "title": "Duration"
                },
                {
                  "name": "preferred_maintenance_window_day",
                  "value": "mon",
                  "form_options": {
                    "type": "select",
                    "options": {
                      "mon": "Monday",
                      "tue": "Tuesday",
                      "wed": "Wednesday",
                      "thu": "Thursday",
                      "fri": "Friday",
                      "sat": "Saturday",
                      "sun": "Sunday"
                    },
                    "depends_on": false
                  },
                  "title": "Start Day"
                },
                {
                  "name": "snapshot_id",
                  "value": "",
                  "form_options": {
                    "type": "select",
                    "options": [
                      "37c1c601962a4b5379e4d0d82942d822"
                    ]
                  },
                  "title": "Type"
                },
                {
                  "name": "snapshot_environment_id",
                  "value": "",
                  "form_options": {
                    "type": "select",
                    "options": [
                      "4721417fa1d1884c6213b04f3ccbde7d"
                    ]
                  },
                  "title": "Type"
                },
                {
                  "name": "create_from_snapshot",
                  "value": false,
                  "form_options": {
                    "environment_with_snapshots_exists": false,
                    "unallocated_snapshots_exists": false,
                    "type": "checkbox"
                  },
                  "title": "Create From Snapshot"
                },
                {
                  "name": "snapshot_id",
                  "value": "",
                  "form_options": {
                    "type": "select",
                    "options": []
                  },
                  "title": "Snapshot ID"
                },
                {
                  "name": "snapshot_provider_id",
                  "value": "",
                  "form_options": {
                    "type": "hidden",
                    "readonly": "readonly"
                  },
                  "title": "Snapshot Provider ID"
                },
                {
                  "name": "snapshot_environment_id",
                  "value": "",
                  "form_options": {
                    "type": "select",
                    "options": []
                  },
                  "title": "Snapshot Environment ID"
                }
              ],
              "interfaces": [
                {
                  "id": "61d095ae-5b9b-4fc5-8ab8-ef7f06c3c5f4",
                  "name": "default",
                  "interface_type": "Protocols::SecurityGroup",
                  "depends": true,
                  "connections": [
                    {
                      "id": "d7e4aa6e-e6a9-4a83-bb47-0b5aba007f8d",
                      "interface_id": "61d095ae-5b9b-4fc5-8ab8-ef7f06c3c5f4",
                      "remote_interface_id": "94c76455-6f91-4128-8db1-d90b323cf891",
                      "internal": false
                    }
                  ]
                },
                {
                  "id": "7bf5ca9d-cd73-402d-839d-aeae9101ec4a",
                  "name": "mysql",
                  "interface_type": "Protocols::Rds",
                  "depends": false,
                  "connections": []
                },
                {
                  "id": "cb7be7e8-d265-475d-a608-dcbdb6a6e669",
                  "name": "sng-v20-$$serviceowner-$$nameenvironment##",
                  "interface_type": "Protocols::SubnetGroup",
                  "depends": true,
                  "connections": [
                    {
                      "id": "826fddc5-623d-43e9-954f-128f35613e7a",
                      "interface_id": "cb7be7e8-d265-475d-a608-dcbdb6a6e669",
                      "remote_interface_id": "f5fe0748-61fa-4fa1-b254-acf961440146",
                      "internal": false
                    }
                  ]
                },
                {
                  "id": "94c0db1a-40a1-4060-874a-f3c581d43f1f",
                  "name": "prat",
                  "interface_type": "Protocols::Vpc",
                  "depends": true,
                  "connections": [
                    {
                      "id": "4272a50d-6f61-4ec5-9f64-ea8155ddba16",
                      "interface_id": "94c0db1a-40a1-4060-874a-f3c581d43f1f",
                      "remote_interface_id": "cbb835cb-991c-4b69-a081-195c597025bf",
                      "internal": false
                    }
                  ]
                }
              ],
              "provides": [
                {
                  "name": "rds",
                  "type": "Protocols::Rds",
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
              "id": "205d740e-b1da-4635-acd6-ffe7e20c633a",
              "name": "auroradb##",
              "type": "Services::Database::Rds::AWS",
              "geometry": {
                "x": "137.28",
                "y": "42.72",
                "width": "56",
                "height": "56"
              },
              "additional_properties": {
                "id": "205d740e-b1da-4635-acd6-ffe7e20c633a",
                "numbered": false,
                "edge": false,
                "service_type": "db",
                "generic_type": "Services::Database::Rds",
                "type": "Services::Database::Rds::AWS",
                "name": "auroradb##",
                "drawable": true,
                "draggable": true,
                "interfaces": [
                  {
                    "id": "f4720e63-e975-4602-8959-da95d24153d8",
                    "name": "default",
                    "depends": true,
                    "connections": [
                      {
                        "id": "42af11d0-5449-492b-82c9-995267170725",
                        "interface_id": "f4720e63-e975-4602-8959-da95d24153d8",
                        "remote_interface_id": "94c76455-6f91-4128-8db1-d90b323cf891",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::SecurityGroup"
                  },
                  {
                    "id": "a85dfb56-7290-4a22-bf8e-37b94f717e45",
                    "name": "aurora",
                    "depends": false,
                    "connections": null,
                    "interface_type": "Protocols::Rds"
                  },
                  {
                    "id": "b42b079a-a04b-4eaf-a8e7-108b81ad8684",
                    "name": "sng-v20-$$serviceowner-$$nameenvironment##",
                    "depends": true,
                    "connections": [
                      {
                        "id": "fa7144fe-bf75-4fb5-bac8-2d569bf5f72f",
                        "interface_id": "b42b079a-a04b-4eaf-a8e7-108b81ad8684",
                        "remote_interface_id": "f5fe0748-61fa-4fa1-b254-acf961440146",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::SubnetGroup"
                  },
                  {
                    "id": "7e154748-d625-4e8d-8672-e4ca36c77870",
                    "name": "prat",
                    "depends": true,
                    "connections": [
                      {
                        "id": "e4749efc-e396-4a03-90fc-9b604b3ee4e1",
                        "interface_id": "7e154748-d625-4e8d-8672-e4ca36c77870",
                        "remote_interface_id": "cbb835cb-991c-4b69-a081-195c597025bf",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::Vpc"
                  }
                ],
                "vpc_id": "16ef4f23-56e2-4f45-a2e3-f20e907bbfcf",
                "primary_key": "",
                "provides": [
                  {
                    "name": "rds",
                    "type": "Protocols::Rds",
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
                "parent_id": "a295c0a9-6d95-4d9d-b4d5-58cf2b85ea36",
                "properties": [
                  {
                    "name": "cluster_id",
                    "value": "",
                    "form_options": {
                      "type": "text"
                    },
                    "title": "DB Cluster Identifier"
                  },
                  {
                    "name": "priority",
                    "form_options": {
                      "type": "select",
                      "keepfirstblank": true,
                      "options": [
                        "tier-0",
                        "tier-1",
                        "tier-2",
                        "tier-3",
                        "tier-4",
                        "tier-5",
                        "tier-6",
                        "tier-7",
                        "tier-8",
                        "tier-9",
                        "tier-10",
                        "tier-11",
                        "tier-12",
                        "tier-13",
                        "tier-14",
                        "tier-15"
                      ]
                    },
                    "title": "Priority"
                  },
                  {
                    "name": "db_name",
                    "form_options": {
                      "type": "text"
                    },
                    "title": "Database Name"
                  },
                  {
                    "name": "endpoint_address",
                    "form_options": {
                      "type": "hidden"
                    },
                    "title": "DB Endpoint Address"
                  },
                  {
                    "name": "engine",
                    "value": "aurora",
                    "form_options": {
                      "type": "hidden"
                    },
                    "title": "DB Engine"
                  },
                  {
                    "name": "license_model",
                    "value": "general-public-license",
                    "form_options": {
                      "type": "select",
                      "options": [
                        "general-public-license"
                      ]
                    },
                    "title": "License Model"
                  },
                  {
                    "name": "engine_version",
                    "value": "5.6.10a",
                    "form_options": {
                      "type": "select",
                      "options": [
                        "5.6.10a"
                      ]
                    },
                    "title": "Engine Version"
                  },
                  {
                    "name": "flavor_id",
                    "value": "db.t2.medium",
                    "form_options": {
                      "type": "select",
                      "options": {
                        "5.6.10a": [
                          "db.t2.medium",
                          "db.r3.large",
                          "db.r3.xlarge",
                          "db.r3.2xlarge",
                          "db.r3.4xlarge",
                          "db.r3.8xlarge"
                        ]
                      }
                    },
                    "title": "Instance Class"
                  },
                  {
                    "name": "iops",
                    "value": "1000",
                    "form_options": {
                      "type": "text",
                      "depends_on": false
                    },
                    "title": "IOPS"
                  },
                  {
                    "name": "publicly_accessible",
                    "value": false,
                    "form_options": {
                      "type": "checkbox"
                    },
                    "title": "Publicly Accessible"
                  },
                  {
                    "name": "availability_zone",
                    "value": "us-east-1b",
                    "form_options": {
                      "type": "select",
                      "options": [],
                      "depends_on": false
                    },
                    "title": "Availability Zone"
                  },
                  {
                    "name": "backup_retention_period",
                    "value": "1",
                    "form_options": {
                      "max": "35",
                      "min": "1",
                      "step": "1",
                      "type": "range"
                    },
                    "title": "Backup Retension Period"
                  },
                  {
                    "name": "master_username",
                    "value": "shah",
                    "form_options": {
                      "type": "text"
                    },
                    "title": "Master Username"
                  },
                  {
                    "name": "password",
                    "value": "qwerty",
                    "form_options": {
                      "type": "password"
                    },
                    "title": "Master Password"
                  },
                  {
                    "name": "port",
                    "value": "3306",
                    "form_options": {
                      "type": "text"
                    },
                    "title": "Database Port"
                  },
                  {
                    "name": "backup_window",
                    "value": "No Preference",
                    "form_options": {
                      "type": "select",
                      "options": [
                        "Select Window",
                        "No Preference"
                      ]
                    },
                    "title": "Backup Window"
                  },
                  {
                    "name": "preferred_backup_window_hour",
                    "value": "0",
                    "form_options": {
                      "type": "select",
                      "options": [
                        "0",
                        "1",
                        "2",
                        "3",
                        "4",
                        "5",
                        "6",
                        "7",
                        "8",
                        "9",
                        "10",
                        "11",
                        "12",
                        "13",
                        "14",
                        "15",
                        "16",
                        "17",
                        "18",
                        "19",
                        "20",
                        "21",
                        "22",
                        "23"
                      ],
                      "related_options_name": "preferred_backup_window_minute",
                      "related_options": [
                        "0",
                        "1",
                        "2",
                        "3",
                        "4",
                        "5",
                        "6",
                        "7",
                        "8",
                        "9",
                        "10",
                        "11",
                        "12",
                        "13",
                        "14",
                        "15",
                        "16",
                        "17",
                        "18",
                        "19",
                        "20",
                        "21",
                        "22",
                        "23",
                        "24",
                        "25",
                        "26",
                        "27",
                        "28",
                        "29",
                        "30",
                        "31",
                        "32",
                        "33",
                        "34",
                        "35",
                        "36",
                        "37",
                        "38",
                        "39",
                        "40",
                        "41",
                        "42",
                        "43",
                        "44",
                        "45",
                        "46",
                        "47",
                        "48",
                        "49",
                        "50",
                        "51",
                        "52",
                        "53",
                        "54",
                        "55",
                        "56",
                        "57",
                        "58",
                        "59"
                      ],
                      "related_value": "0",
                      "unitLabel": "UTC",
                      "depends_on": false
                    },
                    "title": "Start Time"
                  },
                  {
                    "name": "preferred_backup_window_duration",
                    "value": "0.5",
                    "form_options": {
                      "type": "select",
                      "options": [
                        "0.5",
                        "1",
                        "1.5",
                        "2",
                        "2.5",
                        "3"
                      ],
                      "unitLabel": "Hours",
                      "depends_on": false
                    },
                    "title": "Duration"
                  },
                  {
                    "name": "auto_minor_version_upgrade",
                    "value": true,
                    "form_options": {
                      "type": "checkbox"
                    },
                    "title": "Auto Minor Version Upgrade"
                  },
                  {
                    "name": "maintenance_window",
                    "value": "No Preference",
                    "form_options": {
                      "type": "select",
                      "options": [
                        "Select Window",
                        "No Preference"
                      ]
                    },
                    "title": "Maintenance Window"
                  },
                  {
                    "name": "preferred_maintenance_window_hour",
                    "value": "0",
                    "form_options": {
                      "type": "select",
                      "options": [
                        "0",
                        "1",
                        "2",
                        "3",
                        "4",
                        "5",
                        "6",
                        "7",
                        "8",
                        "9",
                        "10",
                        "11",
                        "12",
                        "13",
                        "14",
                        "15",
                        "16",
                        "17",
                        "18",
                        "19",
                        "20",
                        "21",
                        "22",
                        "23"
                      ],
                      "related_options_name": "preferred_maintenance_window_minute",
                      "related_options": [
                        "0",
                        "1",
                        "2",
                        "3",
                        "4",
                        "5",
                        "6",
                        "7",
                        "8",
                        "9",
                        "10",
                        "11",
                        "12",
                        "13",
                        "14",
                        "15",
                        "16",
                        "17",
                        "18",
                        "19",
                        "20",
                        "21",
                        "22",
                        "23",
                        "24",
                        "25",
                        "26",
                        "27",
                        "28",
                        "29",
                        "30",
                        "31",
                        "32",
                        "33",
                        "34",
                        "35",
                        "36",
                        "37",
                        "38",
                        "39",
                        "40",
                        "41",
                        "42",
                        "43",
                        "44",
                        "45",
                        "46",
                        "47",
                        "48",
                        "49",
                        "50",
                        "51",
                        "52",
                        "53",
                        "54",
                        "55",
                        "56",
                        "57",
                        "58",
                        "59"
                      ],
                      "related_value": "0",
                      "unitLabel": "UTC",
                      "depends_on": false
                    },
                    "title": "Start Time"
                  },
                  {
                    "name": "preferred_maintenance_window_duration",
                    "value": "0.5",
                    "form_options": {
                      "type": "select",
                      "options": [
                        "0.5",
                        "1",
                        "1.5",
                        "2",
                        "2.5",
                        "3"
                      ],
                      "unitLabel": "Hours",
                      "depends_on": false
                    },
                    "title": "Duration"
                  },
                  {
                    "name": "preferred_maintenance_window_day",
                    "value": "mon",
                    "form_options": {
                      "type": "select",
                      "options": {
                        "mon": "Monday",
                        "tue": "Tuesday",
                        "wed": "Wednesday",
                        "thu": "Thursday",
                        "fri": "Friday",
                        "sat": "Saturday",
                        "sun": "Sunday"
                      },
                      "depends_on": false
                    },
                    "title": "Start Day"
                  },
                  {
                    "name": "snapshot_id",
                    "value": "",
                    "form_options": {
                      "type": "select",
                      "options": [
                        "e1624304554d52d935ed8eaa69715144"
                      ]
                    },
                    "title": "Type"
                  },
                  {
                    "name": "snapshot_environment_id",
                    "value": "",
                    "form_options": {
                      "type": "select",
                      "options": [
                        "0045b3d268607441164848438b356031"
                      ]
                    },
                    "title": "Type"
                  },
                  {
                    "name": "create_from_snapshot",
                    "value": false,
                    "form_options": {
                      "environment_with_snapshots_exists": false,
                      "unallocated_snapshots_exists": false,
                      "type": "checkbox"
                    },
                    "title": "Create From Snapshot"
                  },
                  {
                    "name": "snapshot_id",
                    "value": "",
                    "form_options": {
                      "type": "select",
                      "options": []
                    },
                    "title": "Snapshot ID"
                  },
                  {
                    "name": "snapshot_provider_id",
                    "value": "",
                    "form_options": {
                      "type": "hidden",
                      "readonly": "readonly"
                    },
                    "title": "Snapshot Provider ID"
                  },
                  {
                    "name": "snapshot_environment_id",
                    "value": "",
                    "form_options": {
                      "type": "select",
                      "options": []
                    },
                    "title": "Snapshot Environment ID"
                  }
                ]
              },
              "properties": [
                {
                  "name": "cluster_id",
                  "value": "",
                  "form_options": {
                    "type": "text"
                  },
                  "title": "DB Cluster Identifier"
                },
                {
                  "name": "priority",
                  "form_options": {
                    "type": "select",
                    "keepfirstblank": true,
                    "options": [
                      "tier-0",
                      "tier-1",
                      "tier-2",
                      "tier-3",
                      "tier-4",
                      "tier-5",
                      "tier-6",
                      "tier-7",
                      "tier-8",
                      "tier-9",
                      "tier-10",
                      "tier-11",
                      "tier-12",
                      "tier-13",
                      "tier-14",
                      "tier-15"
                    ]
                  },
                  "title": "Priority"
                },
                {
                  "name": "db_name",
                  "form_options": {
                    "type": "text"
                  },
                  "title": "Database Name"
                },
                {
                  "name": "endpoint_address",
                  "form_options": {
                    "type": "hidden"
                  },
                  "title": "DB Endpoint Address"
                },
                {
                  "name": "engine",
                  "value": "aurora",
                  "form_options": {
                    "type": "hidden"
                  },
                  "title": "DB Engine"
                },
                {
                  "name": "license_model",
                  "value": "general-public-license",
                  "form_options": {
                    "type": "select",
                    "options": [
                      "general-public-license"
                    ]
                  },
                  "title": "License Model"
                },
                {
                  "name": "engine_version",
                  "value": "5.6.10a",
                  "form_options": {
                    "type": "select",
                    "options": [
                      "5.6.10a"
                    ]
                  },
                  "title": "Engine Version"
                },
                {
                  "name": "flavor_id",
                  "value": "db.t2.medium",
                  "form_options": {
                    "type": "select",
                    "options": {
                      "5.6.10a": [
                        "db.t2.medium",
                        "db.r3.large",
                        "db.r3.xlarge",
                        "db.r3.2xlarge",
                        "db.r3.4xlarge",
                        "db.r3.8xlarge"
                      ]
                    }
                  },
                  "title": "Instance Class"
                },
                {
                  "name": "iops",
                  "value": "1000",
                  "form_options": {
                    "type": "text",
                    "depends_on": false
                  },
                  "title": "IOPS"
                },
                {
                  "name": "publicly_accessible",
                  "value": false,
                  "form_options": {
                    "type": "checkbox"
                  },
                  "title": "Publicly Accessible"
                },
                {
                  "name": "availability_zone",
                  "value": "us-east-1b",
                  "form_options": {
                    "type": "select",
                    "options": [],
                    "depends_on": false
                  },
                  "title": "Availability Zone"
                },
                {
                  "name": "backup_retention_period",
                  "value": "1",
                  "form_options": {
                    "max": "35",
                    "min": "1",
                    "step": "1",
                    "type": "range"
                  },
                  "title": "Backup Retension Period"
                },
                {
                  "name": "master_username",
                  "value": "shah",
                  "form_options": {
                    "type": "text"
                  },
                  "title": "Master Username"
                },
                {
                  "name": "password",
                  "value": "qwerty",
                  "form_options": {
                    "type": "password"
                  },
                  "title": "Master Password"
                },
                {
                  "name": "port",
                  "value": "3306",
                  "form_options": {
                    "type": "text"
                  },
                  "title": "Database Port"
                },
                {
                  "name": "backup_window",
                  "value": "No Preference",
                  "form_options": {
                    "type": "select",
                    "options": [
                      "Select Window",
                      "No Preference"
                    ]
                  },
                  "title": "Backup Window"
                },
                {
                  "name": "preferred_backup_window_hour",
                  "value": "0",
                  "form_options": {
                    "type": "select",
                    "options": [
                      "0",
                      "1",
                      "2",
                      "3",
                      "4",
                      "5",
                      "6",
                      "7",
                      "8",
                      "9",
                      "10",
                      "11",
                      "12",
                      "13",
                      "14",
                      "15",
                      "16",
                      "17",
                      "18",
                      "19",
                      "20",
                      "21",
                      "22",
                      "23"
                    ],
                    "related_options_name": "preferred_backup_window_minute",
                    "related_options": [
                      "0",
                      "1",
                      "2",
                      "3",
                      "4",
                      "5",
                      "6",
                      "7",
                      "8",
                      "9",
                      "10",
                      "11",
                      "12",
                      "13",
                      "14",
                      "15",
                      "16",
                      "17",
                      "18",
                      "19",
                      "20",
                      "21",
                      "22",
                      "23",
                      "24",
                      "25",
                      "26",
                      "27",
                      "28",
                      "29",
                      "30",
                      "31",
                      "32",
                      "33",
                      "34",
                      "35",
                      "36",
                      "37",
                      "38",
                      "39",
                      "40",
                      "41",
                      "42",
                      "43",
                      "44",
                      "45",
                      "46",
                      "47",
                      "48",
                      "49",
                      "50",
                      "51",
                      "52",
                      "53",
                      "54",
                      "55",
                      "56",
                      "57",
                      "58",
                      "59"
                    ],
                    "related_value": "0",
                    "unitLabel": "UTC",
                    "depends_on": false
                  },
                  "title": "Start Time"
                },
                {
                  "name": "preferred_backup_window_duration",
                  "value": "0.5",
                  "form_options": {
                    "type": "select",
                    "options": [
                      "0.5",
                      "1",
                      "1.5",
                      "2",
                      "2.5",
                      "3"
                    ],
                    "unitLabel": "Hours",
                    "depends_on": false
                  },
                  "title": "Duration"
                },
                {
                  "name": "auto_minor_version_upgrade",
                  "value": true,
                  "form_options": {
                    "type": "checkbox"
                  },
                  "title": "Auto Minor Version Upgrade"
                },
                {
                  "name": "maintenance_window",
                  "value": "No Preference",
                  "form_options": {
                    "type": "select",
                    "options": [
                      "Select Window",
                      "No Preference"
                    ]
                  },
                  "title": "Maintenance Window"
                },
                {
                  "name": "preferred_maintenance_window_hour",
                  "value": "0",
                  "form_options": {
                    "type": "select",
                    "options": [
                      "0",
                      "1",
                      "2",
                      "3",
                      "4",
                      "5",
                      "6",
                      "7",
                      "8",
                      "9",
                      "10",
                      "11",
                      "12",
                      "13",
                      "14",
                      "15",
                      "16",
                      "17",
                      "18",
                      "19",
                      "20",
                      "21",
                      "22",
                      "23"
                    ],
                    "related_options_name": "preferred_maintenance_window_minute",
                    "related_options": [
                      "0",
                      "1",
                      "2",
                      "3",
                      "4",
                      "5",
                      "6",
                      "7",
                      "8",
                      "9",
                      "10",
                      "11",
                      "12",
                      "13",
                      "14",
                      "15",
                      "16",
                      "17",
                      "18",
                      "19",
                      "20",
                      "21",
                      "22",
                      "23",
                      "24",
                      "25",
                      "26",
                      "27",
                      "28",
                      "29",
                      "30",
                      "31",
                      "32",
                      "33",
                      "34",
                      "35",
                      "36",
                      "37",
                      "38",
                      "39",
                      "40",
                      "41",
                      "42",
                      "43",
                      "44",
                      "45",
                      "46",
                      "47",
                      "48",
                      "49",
                      "50",
                      "51",
                      "52",
                      "53",
                      "54",
                      "55",
                      "56",
                      "57",
                      "58",
                      "59"
                    ],
                    "related_value": "0",
                    "unitLabel": "UTC",
                    "depends_on": false
                  },
                  "title": "Start Time"
                },
                {
                  "name": "preferred_maintenance_window_duration",
                  "value": "0.5",
                  "form_options": {
                    "type": "select",
                    "options": [
                      "0.5",
                      "1",
                      "1.5",
                      "2",
                      "2.5",
                      "3"
                    ],
                    "unitLabel": "Hours",
                    "depends_on": false
                  },
                  "title": "Duration"
                },
                {
                  "name": "preferred_maintenance_window_day",
                  "value": "mon",
                  "form_options": {
                    "type": "select",
                    "options": {
                      "mon": "Monday",
                      "tue": "Tuesday",
                      "wed": "Wednesday",
                      "thu": "Thursday",
                      "fri": "Friday",
                      "sat": "Saturday",
                      "sun": "Sunday"
                    },
                    "depends_on": false
                  },
                  "title": "Start Day"
                },
                {
                  "name": "snapshot_id",
                  "value": "",
                  "form_options": {
                    "type": "select",
                    "options": [
                      "e1624304554d52d935ed8eaa69715144"
                    ]
                  },
                  "title": "Type"
                },
                {
                  "name": "snapshot_environment_id",
                  "value": "",
                  "form_options": {
                    "type": "select",
                    "options": [
                      "0045b3d268607441164848438b356031"
                    ]
                  },
                  "title": "Type"
                },
                {
                  "name": "create_from_snapshot",
                  "value": false,
                  "form_options": {
                    "environment_with_snapshots_exists": false,
                    "unallocated_snapshots_exists": false,
                    "type": "checkbox"
                  },
                  "title": "Create From Snapshot"
                },
                {
                  "name": "snapshot_id",
                  "value": "",
                  "form_options": {
                    "type": "select",
                    "options": []
                  },
                  "title": "Snapshot ID"
                },
                {
                  "name": "snapshot_provider_id",
                  "value": "",
                  "form_options": {
                    "type": "hidden",
                    "readonly": "readonly"
                  },
                  "title": "Snapshot Provider ID"
                },
                {
                  "name": "snapshot_environment_id",
                  "value": "",
                  "form_options": {
                    "type": "select",
                    "options": []
                  },
                  "title": "Snapshot Environment ID"
                }
              ],
              "interfaces": [
                {
                  "id": "f4720e63-e975-4602-8959-da95d24153d8",
                  "name": "default",
                  "interface_type": "Protocols::SecurityGroup",
                  "depends": true,
                  "connections": [
                    {
                      "id": "42af11d0-5449-492b-82c9-995267170725",
                      "interface_id": "f4720e63-e975-4602-8959-da95d24153d8",
                      "remote_interface_id": "94c76455-6f91-4128-8db1-d90b323cf891",
                      "internal": false
                    }
                  ]
                },
                {
                  "id": "a85dfb56-7290-4a22-bf8e-37b94f717e45",
                  "name": "aurora",
                  "interface_type": "Protocols::Rds",
                  "depends": false,
                  "connections": []
                },
                {
                  "id": "b42b079a-a04b-4eaf-a8e7-108b81ad8684",
                  "name": "sng-v20-$$serviceowner-$$nameenvironment##",
                  "interface_type": "Protocols::SubnetGroup",
                  "depends": true,
                  "connections": [
                    {
                      "id": "fa7144fe-bf75-4fb5-bac8-2d569bf5f72f",
                      "interface_id": "b42b079a-a04b-4eaf-a8e7-108b81ad8684",
                      "remote_interface_id": "f5fe0748-61fa-4fa1-b254-acf961440146",
                      "internal": false
                    }
                  ]
                },
                {
                  "id": "7e154748-d625-4e8d-8672-e4ca36c77870",
                  "name": "prat",
                  "interface_type": "Protocols::Vpc",
                  "depends": true,
                  "connections": [
                    {
                      "id": "e4749efc-e396-4a03-90fc-9b604b3ee4e1",
                      "interface_id": "7e154748-d625-4e8d-8672-e4ca36c77870",
                      "remote_interface_id": "cbb835cb-991c-4b69-a081-195c597025bf",
                      "internal": false
                    }
                  ]
                }
              ],
              "provides": [
                {
                  "name": "rds",
                  "type": "Protocols::Rds",
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
              "id": "fb49128b-7e3c-4292-ae16-8621cc036a60",
              "name": "ASG-V20-##-",
              "type": "Services::Network::AutoScaling::AWS",
              "geometry": {
                "x": "30",
                "y": "49.04",
                "width": "100",
                "height": "100"
              },
              "additional_properties": {
                "id": "fb49128b-7e3c-4292-ae16-8621cc036a60",
                "numbered": false,
                "edge": false,
                "service_type": "autoscaling",
                "generic_type": "Services::Network::AutoScaling",
                "type": "Services::Network::AutoScaling::AWS",
                "name": "ASG-V20-##-",
                "drawable": true,
                "draggable": true,
                "interfaces": [
                  {
                    "id": "d4e602e7-1ae0-442a-8653-834ecb6d578e",
                    "name": "auto_scaling",
                    "depends": false,
                    "connections": null,
                    "interface_type": "Protocols::AutoScaling"
                  },
                  {
                    "id": "e7d8bab3-0610-48b0-8df1-983bad5fed73",
                    "name": "SN-V20-$$servername-##",
                    "depends": true,
                    "connections": [
                      {
                        "id": "f4b2d5e6-a62e-4ce8-a855-1518b3e9df13",
                        "interface_id": "e7d8bab3-0610-48b0-8df1-983bad5fed73",
                        "remote_interface_id": "00a5f387-643e-46bb-949c-3062b5bfebec",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::Subnet"
                  },
                  {
                    "id": "d0ba25da-6602-43f3-83b5-7bd3b2ad5c0b",
                    "name": "prat",
                    "depends": true,
                    "connections": [
                      {
                        "id": "34f51621-4765-4475-8abb-d1a575dba7c9",
                        "interface_id": "d0ba25da-6602-43f3-83b5-7bd3b2ad5c0b",
                        "remote_interface_id": "cbb835cb-991c-4b69-a081-195c597025bf",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::Vpc"
                  },
                  {
                    "id": "a92f6c7e-8640-4065-b299-c83183c24c5e",
                    "name": "LCF-V20-##-",
                    "depends": true,
                    "connections": [
                      {
                        "id": "a18e926b-6174-48ce-b262-7f5d945f3dad",
                        "interface_id": "a92f6c7e-8640-4065-b299-c83183c24c5e",
                        "remote_interface_id": "927c5daf-d1cb-4eb7-bc0e-628f720e54a8",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::AutoScalingConfiguration"
                  }
                ],
                "vpc_id": "16ef4f23-56e2-4f45-a2e3-f20e907bbfcf",
                "primary_key": "",
                "provides": [
                  {
                    "name": "auto_scaling",
                    "type": "Protocols::AutoScaling",
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
                "parent_id": [
                  "f167f871-edca-47cc-b2c6-9e168f152c77"
                ],
                "properties": [
                  {
                    "name": "min_size",
                    "value": "0",
                    "form_options": {
                      "type": "text"
                    },
                    "title": "Minimum instances"
                  },
                  {
                    "name": "max_size",
                    "value": "1",
                    "form_options": {
                      "type": "text"
                    },
                    "title": "Maximum instances"
                  },
                  {
                    "name": "desired_capacity",
                    "value": "0",
                    "form_options": {
                      "type": "text"
                    },
                    "title": "Desired Capacity (instances)"
                  },
                  {
                    "name": "default_cooldown",
                    "value": "300",
                    "form_options": {
                      "type": "text"
                    },
                    "title": "Default Cooldown"
                  },
                  {
                    "name": "health_check_type",
                    "value": "EC2",
                    "form_options": {
                      "type": "select",
                      "options": [
                        "EC2",
                        "ELB"
                      ]
                    },
                    "title": "Health Check Type"
                  },
                  {
                    "name": "health_check_grace_period",
                    "value": "20",
                    "form_options": {
                      "type": "text"
                    },
                    "title": "Health Check Grace Period (sec)"
                  },
                  {
                    "name": "policies",
                    "form_options": {
                      "type": "array",
                      "section": "policies"
                    },
                    "title": "Policies"
                  }
                ]
              },
              "properties": [
                {
                  "name": "min_size",
                  "value": "0",
                  "form_options": {
                    "type": "text"
                  },
                  "title": "Minimum instances"
                },
                {
                  "name": "max_size",
                  "value": "1",
                  "form_options": {
                    "type": "text"
                  },
                  "title": "Maximum instances"
                },
                {
                  "name": "desired_capacity",
                  "value": "0",
                  "form_options": {
                    "type": "text"
                  },
                  "title": "Desired Capacity (instances)"
                },
                {
                  "name": "default_cooldown",
                  "value": "300",
                  "form_options": {
                    "type": "text"
                  },
                  "title": "Default Cooldown"
                },
                {
                  "name": "health_check_type",
                  "value": "EC2",
                  "form_options": {
                    "type": "select",
                    "options": [
                      "EC2",
                      "ELB"
                    ]
                  },
                  "title": "Health Check Type"
                },
                {
                  "name": "health_check_grace_period",
                  "value": "20",
                  "form_options": {
                    "type": "text"
                  },
                  "title": "Health Check Grace Period (sec)"
                },
                {
                  "name": "policies",
                  "form_options": {
                    "type": "array",
                    "section": "policies"
                  },
                  "title": "Policies"
                }
              ],
              "interfaces": [
                {
                  "id": "d4e602e7-1ae0-442a-8653-834ecb6d578e",
                  "name": "auto_scaling",
                  "interface_type": "Protocols::AutoScaling",
                  "depends": false,
                  "connections": []
                },
                {
                  "id": "e7d8bab3-0610-48b0-8df1-983bad5fed73",
                  "name": "SN-V20-$$servername-##",
                  "interface_type": "Protocols::Subnet",
                  "depends": true,
                  "connections": [
                    {
                      "id": "f4b2d5e6-a62e-4ce8-a855-1518b3e9df13",
                      "interface_id": "e7d8bab3-0610-48b0-8df1-983bad5fed73",
                      "remote_interface_id": "00a5f387-643e-46bb-949c-3062b5bfebec",
                      "internal": false
                    }
                  ]
                },
                {
                  "id": "d0ba25da-6602-43f3-83b5-7bd3b2ad5c0b",
                  "name": "prat",
                  "interface_type": "Protocols::Vpc",
                  "depends": true,
                  "connections": [
                    {
                      "id": "34f51621-4765-4475-8abb-d1a575dba7c9",
                      "interface_id": "d0ba25da-6602-43f3-83b5-7bd3b2ad5c0b",
                      "remote_interface_id": "cbb835cb-991c-4b69-a081-195c597025bf",
                      "internal": false
                    }
                  ]
                },
                {
                  "id": "a92f6c7e-8640-4065-b299-c83183c24c5e",
                  "name": "LCF-V20-##-",
                  "interface_type": "Protocols::AutoScalingConfiguration",
                  "depends": true,
                  "connections": [
                    {
                      "id": "a18e926b-6174-48ce-b262-7f5d945f3dad",
                      "interface_id": "a92f6c7e-8640-4065-b299-c83183c24c5e",
                      "remote_interface_id": "927c5daf-d1cb-4eb7-bc0e-628f720e54a8",
                      "internal": false
                    }
                  ]
                }
              ],
              "provides": [
                {
                  "name": "auto_scaling",
                  "type": "Protocols::AutoScaling",
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
              "id": "4ef633e3-a1df-4672-85ff-23270bc4346a",
              "name": "ASG-V20-##-",
              "type": "Services::Network::AutoScaling::AWS",
              "geometry": {
                "x": "30",
                "y": "50",
                "width": "100",
                "height": "100"
              },
              "additional_properties": {
                "id": "4ef633e3-a1df-4672-85ff-23270bc4346a",
                "numbered": false,
                "edge": false,
                "service_type": "autoscaling",
                "generic_type": "Services::Network::AutoScaling",
                "type": "Services::Network::AutoScaling::AWS",
                "name": "ASG-V20-##-",
                "drawable": true,
                "draggable": true,
                "interfaces": [
                  {
                    "id": "f9c2d471-feda-4d65-9d4e-613e34a75972",
                    "name": "auto_scaling",
                    "depends": false,
                    "connections": null,
                    "interface_type": "Protocols::AutoScaling"
                  },
                  {
                    "id": "6543b141-4825-403d-8b59-14961f98f405",
                    "name": "SN-V20-$$servername-##",
                    "depends": true,
                    "connections": [
                      {
                        "id": "57dcb278-ead0-46e6-829e-191057e6d360",
                        "interface_id": "6543b141-4825-403d-8b59-14961f98f405",
                        "remote_interface_id": "ca42bb6b-4723-45b5-bcf6-544b1779edca",
                        "type": "implicit"
                      },
                      {
                        "id": "ac429d6b-055f-42b3-a25c-9dc295e145a2",
                        "interface_id": "6543b141-4825-403d-8b59-14961f98f405",
                        "remote_interface_id": "06b21844-962a-48a0-b1d5-4eac6cac5055",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::Subnet"
                  },
                  {
                    "id": "0c18afd0-18e5-4317-a494-de92b69833c0",
                    "name": "prat",
                    "depends": true,
                    "connections": [
                      {
                        "id": "348368e4-da3c-4046-9696-59f296c50f93",
                        "interface_id": "0c18afd0-18e5-4317-a494-de92b69833c0",
                        "remote_interface_id": "cbb835cb-991c-4b69-a081-195c597025bf",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::Vpc"
                  },
                  {
                    "id": "b8cd99a2-1a73-4d2b-a8db-95bdbd36088e",
                    "name": "LCF-V20-##-",
                    "depends": true,
                    "connections": [
                      {
                        "id": "ecc88184-79da-496a-b00e-f4ad7be9ef3a",
                        "interface_id": "b8cd99a2-1a73-4d2b-a8db-95bdbd36088e",
                        "remote_interface_id": "8740934a-2a20-471b-9eff-678e56747a1f",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::AutoScalingConfiguration"
                  }
                ],
                "vpc_id": "16ef4f23-56e2-4f45-a2e3-f20e907bbfcf",
                "primary_key": "",
                "provides": [
                  {
                    "name": "auto_scaling",
                    "type": "Protocols::AutoScaling",
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
                "parent_id": [
                  "19ea9572-c5ed-49f9-aff1-8eded2a2ef63",
                  "c6fe71d4-e3f1-4de7-ad85-b54a2616a033"
                ],
                "properties": [
                  {
                    "name": "min_size",
                    "value": "0",
                    "form_options": {
                      "type": "text"
                    },
                    "title": "Minimum instances"
                  },
                  {
                    "name": "max_size",
                    "value": "1",
                    "form_options": {
                      "type": "text"
                    },
                    "title": "Maximum instances"
                  },
                  {
                    "name": "desired_capacity",
                    "value": "0",
                    "form_options": {
                      "type": "text"
                    },
                    "title": "Desired Capacity (instances)"
                  },
                  {
                    "name": "default_cooldown",
                    "value": "300",
                    "form_options": {
                      "type": "text"
                    },
                    "title": "Default Cooldown"
                  },
                  {
                    "name": "health_check_type",
                    "value": "EC2",
                    "form_options": {
                      "type": "select",
                      "options": [
                        "EC2",
                        "ELB"
                      ]
                    },
                    "title": "Health Check Type"
                  },
                  {
                    "name": "health_check_grace_period",
                    "value": "20",
                    "form_options": {
                      "type": "text"
                    },
                    "title": "Health Check Grace Period (sec)"
                  },
                  {
                    "name": "policies",
                    "form_options": {
                      "type": "array",
                      "section": "policies"
                    },
                    "title": "Policies"
                  }
                ]
              },
              "properties": [
                {
                  "name": "min_size",
                  "value": "0",
                  "form_options": {
                    "type": "text"
                  },
                  "title": "Minimum instances"
                },
                {
                  "name": "max_size",
                  "value": "1",
                  "form_options": {
                    "type": "text"
                  },
                  "title": "Maximum instances"
                },
                {
                  "name": "desired_capacity",
                  "value": "0",
                  "form_options": {
                    "type": "text"
                  },
                  "title": "Desired Capacity (instances)"
                },
                {
                  "name": "default_cooldown",
                  "value": "300",
                  "form_options": {
                    "type": "text"
                  },
                  "title": "Default Cooldown"
                },
                {
                  "name": "health_check_type",
                  "value": "EC2",
                  "form_options": {
                    "type": "select",
                    "options": [
                      "EC2",
                      "ELB"
                    ]
                  },
                  "title": "Health Check Type"
                },
                {
                  "name": "health_check_grace_period",
                  "value": "20",
                  "form_options": {
                    "type": "text"
                  },
                  "title": "Health Check Grace Period (sec)"
                },
                {
                  "name": "policies",
                  "form_options": {
                    "type": "array",
                    "section": "policies"
                  },
                  "title": "Policies"
                }
              ],
              "interfaces": [
                {
                  "id": "f9c2d471-feda-4d65-9d4e-613e34a75972",
                  "name": "auto_scaling",
                  "interface_type": "Protocols::AutoScaling",
                  "depends": false,
                  "connections": []
                },
                {
                  "id": "6543b141-4825-403d-8b59-14961f98f405",
                  "name": "SN-V20-$$servername-##",
                  "interface_type": "Protocols::Subnet",
                  "depends": true,
                  "connections": [
                    {
                      "id": "57dcb278-ead0-46e6-829e-191057e6d360",
                      "interface_id": "6543b141-4825-403d-8b59-14961f98f405",
                      "remote_interface_id": "ca42bb6b-4723-45b5-bcf6-544b1779edca",
                      "internal": false
                    },
                    {
                      "id": "ac429d6b-055f-42b3-a25c-9dc295e145a2",
                      "interface_id": "6543b141-4825-403d-8b59-14961f98f405",
                      "remote_interface_id": "06b21844-962a-48a0-b1d5-4eac6cac5055",
                      "internal": false
                    }
                  ]
                },
                {
                  "id": "0c18afd0-18e5-4317-a494-de92b69833c0",
                  "name": "prat",
                  "interface_type": "Protocols::Vpc",
                  "depends": true,
                  "connections": [
                    {
                      "id": "348368e4-da3c-4046-9696-59f296c50f93",
                      "interface_id": "0c18afd0-18e5-4317-a494-de92b69833c0",
                      "remote_interface_id": "cbb835cb-991c-4b69-a081-195c597025bf",
                      "internal": false
                    }
                  ]
                },
                {
                  "id": "b8cd99a2-1a73-4d2b-a8db-95bdbd36088e",
                  "name": "LCF-V20-##-",
                  "interface_type": "Protocols::AutoScalingConfiguration",
                  "depends": true,
                  "connections": [
                    {
                      "id": "ecc88184-79da-496a-b00e-f4ad7be9ef3a",
                      "interface_id": "b8cd99a2-1a73-4d2b-a8db-95bdbd36088e",
                      "remote_interface_id": "8740934a-2a20-471b-9eff-678e56747a1f",
                      "internal": false
                    }
                  ]
                }
              ],
              "provides": [
                {
                  "name": "auto_scaling",
                  "type": "Protocols::AutoScaling",
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
              "id": "ba7c471d-e73e-4b63-b536-aae3154d2b30",
              "name": "LCF-V20-##-",
              "type": "Services::Network::AutoScalingConfiguration::AWS",
              "geometry": {
                "x": "20",
                "y": "30.0000000000001",
                "width": "56",
                "height": "56"
              },
              "additional_properties": {
                "id": "ba7c471d-e73e-4b63-b536-aae3154d2b30",
                "numbered": false,
                "edge": false,
                "service_type": "autoscalingconfiguration",
                "generic_type": "Services::Compute::Server",
                "type": "Services::Network::AutoScalingConfiguration::AWS",
                "name": "LCF-V20-##-",
                "drawable": true,
                "draggable": true,
                "interfaces": [
                  {
                    "id": "cb458fa9-495c-425b-ba52-2d88f3d4d1dd",
                    "name": "default",
                    "depends": true,
                    "connections": [
                      {
                        "id": "938db6c0-d0d1-464e-b19b-ea2abaa7ffbd",
                        "interface_id": "cb458fa9-495c-425b-ba52-2d88f3d4d1dd",
                        "remote_interface_id": "94c76455-6f91-4128-8db1-d90b323cf891",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::SecurityGroup"
                  },
                  {
                    "id": "8740934a-2a20-471b-9eff-678e56747a1f",
                    "name": "launch-config",
                    "depends": false,
                    "connections": null,
                    "interface_type": "Protocols::AutoScalingConfiguration"
                  },
                  {
                    "id": "ad7133c8-438b-4cf1-9892-c4d142e6eb4d",
                    "name": "prat",
                    "depends": true,
                    "connections": [
                      {
                        "id": "c0132a31-6e1b-4116-ade2-7811370449b7",
                        "interface_id": "ad7133c8-438b-4cf1-9892-c4d142e6eb4d",
                        "remote_interface_id": "cbb835cb-991c-4b69-a081-195c597025bf",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::Vpc"
                  }
                ],
                "vpc_id": "16ef4f23-56e2-4f45-a2e3-f20e907bbfcf",
                "primary_key": "",
                "provides": [],
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
                ],
                "parent_id": "4ef633e3-a1df-4672-85ff-23270bc4346a",
                "properties": [
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
                    "name": "image_id",
                    "value": "ami-4ab1925d",
                    "form_options": {
                      "type": "text",
                      "readonly": "readonly"
                    },
                    "title": "AMI"
                  },
                  {
                    "name": "image_config_id",
                    "value": "a5502d25-3f02-45a6-acb2-453929c3d9bc",
                    "form_options": {
                      "type": "select",
                      "options": {
                        "a5502d25-3f02-45a6-acb2-453929c3d9bc": "q1"
                      },
                      "flavor_ids": {
                        "a5502d25-3f02-45a6-acb2-453929c3d9bc": [
                          "t2.nano",
                          "t2.micro",
                          "t2.small",
                          "t2.medium"
                        ]
                      },
                      "required": true
                    },
                    "title": "AMI Configurations"
                  },
                  {
                    "name": "key_name",
                    "value": "",
                    "form_options": {
                      "type": "select",
                      "options": [
                        "marketplace-test",
                        "marketplace-test",
                        "marketplace-test"
                      ],
                      "data": [
                        "marketplace-test",
                        "marketplace-test",
                        "marketplace-test"
                      ],
                      "keepfirstblank": true,
                      "firstblanktext": "Proceed without a Key Pair"
                    },
                    "title": "Select Key Pair"
                  },
                  {
                    "name": "platform",
                    "value": "non-windows",
                    "form_options": {
                      "type": "hidden"
                    },
                    "title": "Platform"
                  },
                  {
                    "name": "block_device_mappings",
                    "value": [
                      {
                        "DeviceName": "VOL-$$serviceowner-$$function##-V20-",
                        "Ebs.VolumeSize": "60",
                        "Ebs.VolumeType": "General Purpose (SSD)",
                        "Ebs.Iops": "100",
                        "devicepath": "/dev/sda1",
                        "Ebs.DeleteOnTermination": false,
                        "root_device": true,
                        "encrypted": false
                      },
                      {
                        "DeviceName": "VOL-$$serviceowner-$$function##-V20-",
                        "Ebs.VolumeSize": "1",
                        "Ebs.VolumeType": "General Purpose (SSD)",
                        "Ebs.Iops": "100",
                        "devicepath": "/dev/sdf",
                        "Ebs.DeleteOnTermination": false,
                        "root_device": false,
                        "encrypted": false
                      },
                      {
                        "DeviceName": "VOL-$$serviceowner-$$function##-V20-",
                        "Ebs.VolumeSize": "1",
                        "Ebs.VolumeType": "General Purpose (SSD)",
                        "Ebs.Iops": "100",
                        "devicepath": "/dev/sdg",
                        "Ebs.DeleteOnTermination": false,
                        "root_device": false,
                        "encrypted": false
                      }
                    ],
                    "form_options": {
                      "type": "block_device_mappings"
                    },
                    "title": "Block Device Mappings"
                  },
                  {
                    "name": "flavor_id",
                    "value": "t2.nano",
                    "form_options": {
                      "type": "select",
                      "options": [
                        "t2.nano",
                        "t2.micro",
                        "t2.small",
                        "t2.medium"
                      ]
                    },
                    "title": "Instance Type"
                  },
                  {
                    "name": "associate_public_ip",
                    "value": false,
                    "form_options": {
                      "type": "checkbox"
                    },
                    "title": "Associate Public IP"
                  },
                  {
                    "name": "instance_monitoring",
                    "form_options": {
                      "type": "checkbox"
                    },
                    "title": "Instance Monitoring"
                  },
                  {
                    "name": "original_block_device_mappings",
                    "value": [
                      {
                        "Ebs.VolumeSize": "60",
                        "Ebs.VolumeType": "gp2",
                        "Ebs.Iops": "180",
                        "Ebs.DeleteOnTermination": true,
                        "DevicePath": "/dev/sda1",
                        "Ebs.encrypted": false
                      },
                      {
                        "Ebs.VolumeSize": "1",
                        "Ebs.VolumeType": "gp2",
                        "Ebs.Iops": "3",
                        "Ebs.DeleteOnTermination": true,
                        "DevicePath": "/dev/sdf",
                        "Ebs.encrypted": false
                      },
                      {
                        "Ebs.VolumeSize": "1",
                        "Ebs.VolumeType": "gp2",
                        "Ebs.Iops": "3",
                        "Ebs.DeleteOnTermination": true,
                        "DevicePath": "/dev/sdg",
                        "Ebs.encrypted": false
                      }
                    ],
                    "form_options": {
                      "type": "hidden",
                      "readonly": "readonly"
                    },
                    "title": "Original Block Device Mappings"
                  }
                ]
              },
              "properties": [
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
                  "name": "image_id",
                  "value": "ami-4ab1925d",
                  "form_options": {
                    "type": "text",
                    "readonly": "readonly"
                  },
                  "title": "AMI"
                },
                {
                  "name": "image_config_id",
                  "value": "a5502d25-3f02-45a6-acb2-453929c3d9bc",
                  "form_options": {
                    "type": "select",
                    "options": {
                      "a5502d25-3f02-45a6-acb2-453929c3d9bc": "q1"
                    },
                    "flavor_ids": {
                      "a5502d25-3f02-45a6-acb2-453929c3d9bc": [
                        "t2.nano",
                        "t2.micro",
                        "t2.small",
                        "t2.medium"
                      ]
                    },
                    "required": true
                  },
                  "title": "AMI Configurations"
                },
                {
                  "name": "key_name",
                  "value": "",
                  "form_options": {
                    "type": "select",
                    "options": [
                      "marketplace-test",
                      "marketplace-test",
                      "marketplace-test"
                    ],
                    "data": [
                      "marketplace-test",
                      "marketplace-test",
                      "marketplace-test"
                    ],
                    "keepfirstblank": true,
                    "firstblanktext": "Proceed without a Key Pair"
                  },
                  "title": "Select Key Pair"
                },
                {
                  "name": "platform",
                  "value": "non-windows",
                  "form_options": {
                    "type": "hidden"
                  },
                  "title": "Platform"
                },
                {
                  "name": "block_device_mappings",
                  "value": [
                    {
                      "DeviceName": "VOL-$$serviceowner-$$function##-V20-",
                      "Ebs.VolumeSize": "60",
                      "Ebs.VolumeType": "General Purpose (SSD)",
                      "Ebs.Iops": "100",
                      "devicepath": "/dev/sda1",
                      "Ebs.DeleteOnTermination": false,
                      "root_device": true,
                      "encrypted": false
                    },
                    {
                      "DeviceName": "VOL-$$serviceowner-$$function##-V20-",
                      "Ebs.VolumeSize": "1",
                      "Ebs.VolumeType": "General Purpose (SSD)",
                      "Ebs.Iops": "100",
                      "devicepath": "/dev/sdf",
                      "Ebs.DeleteOnTermination": false,
                      "root_device": false,
                      "encrypted": false
                    },
                    {
                      "DeviceName": "VOL-$$serviceowner-$$function##-V20-",
                      "Ebs.VolumeSize": "1",
                      "Ebs.VolumeType": "General Purpose (SSD)",
                      "Ebs.Iops": "100",
                      "devicepath": "/dev/sdg",
                      "Ebs.DeleteOnTermination": false,
                      "root_device": false,
                      "encrypted": false
                    }
                  ],
                  "form_options": {
                    "type": "block_device_mappings"
                  },
                  "title": "Block Device Mappings"
                },
                {
                  "name": "flavor_id",
                  "value": "t2.nano",
                  "form_options": {
                    "type": "select",
                    "options": [
                      "t2.nano",
                      "t2.micro",
                      "t2.small",
                      "t2.medium"
                    ]
                  },
                  "title": "Instance Type"
                },
                {
                  "name": "associate_public_ip",
                  "value": false,
                  "form_options": {
                    "type": "checkbox"
                  },
                  "title": "Associate Public IP"
                },
                {
                  "name": "instance_monitoring",
                  "form_options": {
                    "type": "checkbox"
                  },
                  "title": "Instance Monitoring"
                },
                {
                  "name": "original_block_device_mappings",
                  "value": [
                    {
                      "Ebs.VolumeSize": "60",
                      "Ebs.VolumeType": "gp2",
                      "Ebs.Iops": "180",
                      "Ebs.DeleteOnTermination": true,
                      "DevicePath": "/dev/sda1",
                      "Ebs.encrypted": false
                    },
                    {
                      "Ebs.VolumeSize": "1",
                      "Ebs.VolumeType": "gp2",
                      "Ebs.Iops": "3",
                      "Ebs.DeleteOnTermination": true,
                      "DevicePath": "/dev/sdf",
                      "Ebs.encrypted": false
                    },
                    {
                      "Ebs.VolumeSize": "1",
                      "Ebs.VolumeType": "gp2",
                      "Ebs.Iops": "3",
                      "Ebs.DeleteOnTermination": true,
                      "DevicePath": "/dev/sdg",
                      "Ebs.encrypted": false
                    }
                  ],
                  "form_options": {
                    "type": "hidden",
                    "readonly": "readonly"
                  },
                  "title": "Original Block Device Mappings"
                }
              ],
              "interfaces": [
                {
                  "id": "cb458fa9-495c-425b-ba52-2d88f3d4d1dd",
                  "name": "default",
                  "interface_type": "Protocols::SecurityGroup",
                  "depends": true,
                  "connections": [
                    {
                      "id": "938db6c0-d0d1-464e-b19b-ea2abaa7ffbd",
                      "interface_id": "cb458fa9-495c-425b-ba52-2d88f3d4d1dd",
                      "remote_interface_id": "94c76455-6f91-4128-8db1-d90b323cf891",
                      "internal": false
                    }
                  ]
                },
                {
                  "id": "8740934a-2a20-471b-9eff-678e56747a1f",
                  "name": "launch-config",
                  "interface_type": "Protocols::AutoScalingConfiguration",
                  "depends": false,
                  "connections": []
                },
                {
                  "id": "ad7133c8-438b-4cf1-9892-c4d142e6eb4d",
                  "name": "prat",
                  "interface_type": "Protocols::Vpc",
                  "depends": true,
                  "connections": [
                    {
                      "id": "c0132a31-6e1b-4116-ade2-7811370449b7",
                      "interface_id": "ad7133c8-438b-4cf1-9892-c4d142e6eb4d",
                      "remote_interface_id": "cbb835cb-991c-4b69-a081-195c597025bf",
                      "internal": false
                    }
                  ]
                }
              ],
              "provides": [],
              "depends": []
            },
            {
              "id": "8e991356-23ad-4eb8-97a1-7c17b39a5826",
              "name": "LCF-V20-##-",
              "type": "Services::Network::AutoScalingConfiguration::AWS",
              "geometry": {
                "x": "20",
                "y": "20",
                "width": "56",
                "height": "56"
              },
              "additional_properties": {
                "id": "8e991356-23ad-4eb8-97a1-7c17b39a5826",
                "numbered": false,
                "edge": false,
                "service_type": "autoscalingconfiguration",
                "generic_type": "Services::Compute::Server",
                "type": "Services::Network::AutoScalingConfiguration::AWS",
                "name": "LCF-V20-##-",
                "drawable": true,
                "draggable": true,
                "interfaces": [
                  {
                    "id": "7fdcc014-422f-4c83-bf9e-dffbe4e34ab6",
                    "name": "default",
                    "depends": true,
                    "connections": [
                      {
                        "id": "fa7a7776-1133-4999-bfec-418a0837e945",
                        "interface_id": "7fdcc014-422f-4c83-bf9e-dffbe4e34ab6",
                        "remote_interface_id": "94c76455-6f91-4128-8db1-d90b323cf891",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::SecurityGroup"
                  },
                  {
                    "id": "927c5daf-d1cb-4eb7-bc0e-628f720e54a8",
                    "name": "launch-config",
                    "depends": false,
                    "connections": null,
                    "interface_type": "Protocols::AutoScalingConfiguration"
                  },
                  {
                    "id": "a5f44153-0d77-4385-818b-5546d1546918",
                    "name": "prat",
                    "depends": true,
                    "connections": [
                      {
                        "id": "54c786ad-9343-4aa3-aba5-7344ba086bfd",
                        "interface_id": "a5f44153-0d77-4385-818b-5546d1546918",
                        "remote_interface_id": "cbb835cb-991c-4b69-a081-195c597025bf",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::Vpc"
                  }
                ],
                "vpc_id": "16ef4f23-56e2-4f45-a2e3-f20e907bbfcf",
                "primary_key": "",
                "provides": [],
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
                ],
                "parent_id": "fb49128b-7e3c-4292-ae16-8621cc036a60",
                "properties": [
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
                    "name": "image_id",
                    "value": "ami-4ab1925d",
                    "form_options": {
                      "type": "text",
                      "readonly": "readonly"
                    },
                    "title": "AMI"
                  },
                  {
                    "name": "image_config_id",
                    "value": "a5502d25-3f02-45a6-acb2-453929c3d9bc",
                    "form_options": {
                      "type": "select",
                      "options": {
                        "a5502d25-3f02-45a6-acb2-453929c3d9bc": "q1"
                      },
                      "flavor_ids": {
                        "a5502d25-3f02-45a6-acb2-453929c3d9bc": [
                          "t2.nano",
                          "t2.micro",
                          "t2.small",
                          "t2.medium"
                        ]
                      },
                      "required": true
                    },
                    "title": "AMI Configurations"
                  },
                  {
                    "name": "key_name",
                    "value": "",
                    "form_options": {
                      "type": "select",
                      "options": [
                        "marketplace-test",
                        "marketplace-test",
                        "marketplace-test"
                      ],
                      "data": [
                        "marketplace-test",
                        "marketplace-test",
                        "marketplace-test"
                      ],
                      "keepfirstblank": true,
                      "firstblanktext": "Proceed without a Key Pair"
                    },
                    "title": "Select Key Pair"
                  },
                  {
                    "name": "platform",
                    "value": "non-windows",
                    "form_options": {
                      "type": "hidden"
                    },
                    "title": "Platform"
                  },
                  {
                    "name": "block_device_mappings",
                    "value": [
                      {
                        "DeviceName": "VOL-$$serviceowner-$$function##-V20-",
                        "Ebs.VolumeSize": "60",
                        "Ebs.VolumeType": "General Purpose (SSD)",
                        "Ebs.Iops": "100",
                        "devicepath": "/dev/sda1",
                        "Ebs.DeleteOnTermination": false,
                        "root_device": true,
                        "encrypted": false
                      },
                      {
                        "DeviceName": "VOL-$$serviceowner-$$function##-V20-",
                        "Ebs.VolumeSize": "1",
                        "Ebs.VolumeType": "General Purpose (SSD)",
                        "Ebs.Iops": "100",
                        "devicepath": "/dev/sdf",
                        "Ebs.DeleteOnTermination": false,
                        "root_device": false,
                        "encrypted": false
                      },
                      {
                        "DeviceName": "VOL-$$serviceowner-$$function##-V20-",
                        "Ebs.VolumeSize": "1",
                        "Ebs.VolumeType": "General Purpose (SSD)",
                        "Ebs.Iops": "100",
                        "devicepath": "/dev/sdg",
                        "Ebs.DeleteOnTermination": false,
                        "root_device": false,
                        "encrypted": false
                      }
                    ],
                    "form_options": {
                      "type": "block_device_mappings"
                    },
                    "title": "Block Device Mappings"
                  },
                  {
                    "name": "flavor_id",
                    "value": "t2.nano",
                    "form_options": {
                      "type": "select",
                      "options": [
                        "t2.nano",
                        "t2.micro",
                        "t2.small",
                        "t2.medium"
                      ]
                    },
                    "title": "Instance Type"
                  },
                  {
                    "name": "associate_public_ip",
                    "value": false,
                    "form_options": {
                      "type": "checkbox"
                    },
                    "title": "Associate Public IP"
                  },
                  {
                    "name": "instance_monitoring",
                    "form_options": {
                      "type": "checkbox"
                    },
                    "title": "Instance Monitoring"
                  },
                  {
                    "name": "original_block_device_mappings",
                    "value": [
                      {
                        "Ebs.VolumeSize": "60",
                        "Ebs.VolumeType": "gp2",
                        "Ebs.Iops": "180",
                        "Ebs.DeleteOnTermination": true,
                        "DevicePath": "/dev/sda1",
                        "Ebs.encrypted": false
                      },
                      {
                        "Ebs.VolumeSize": "1",
                        "Ebs.VolumeType": "gp2",
                        "Ebs.Iops": "3",
                        "Ebs.DeleteOnTermination": true,
                        "DevicePath": "/dev/sdf",
                        "Ebs.encrypted": false
                      },
                      {
                        "Ebs.VolumeSize": "1",
                        "Ebs.VolumeType": "gp2",
                        "Ebs.Iops": "3",
                        "Ebs.DeleteOnTermination": true,
                        "DevicePath": "/dev/sdg",
                        "Ebs.encrypted": false
                      }
                    ],
                    "form_options": {
                      "type": "hidden",
                      "readonly": "readonly"
                    },
                    "title": "Original Block Device Mappings"
                  }
                ]
              },
              "properties": [
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
                  "name": "image_id",
                  "value": "ami-4ab1925d",
                  "form_options": {
                    "type": "text",
                    "readonly": "readonly"
                  },
                  "title": "AMI"
                },
                {
                  "name": "image_config_id",
                  "value": "a5502d25-3f02-45a6-acb2-453929c3d9bc",
                  "form_options": {
                    "type": "select",
                    "options": {
                      "a5502d25-3f02-45a6-acb2-453929c3d9bc": "q1"
                    },
                    "flavor_ids": {
                      "a5502d25-3f02-45a6-acb2-453929c3d9bc": [
                        "t2.nano",
                        "t2.micro",
                        "t2.small",
                        "t2.medium"
                      ]
                    },
                    "required": true
                  },
                  "title": "AMI Configurations"
                },
                {
                  "name": "key_name",
                  "value": "",
                  "form_options": {
                    "type": "select",
                    "options": [
                      "marketplace-test",
                      "marketplace-test",
                      "marketplace-test"
                    ],
                    "data": [
                      "marketplace-test",
                      "marketplace-test",
                      "marketplace-test"
                    ],
                    "keepfirstblank": true,
                    "firstblanktext": "Proceed without a Key Pair"
                  },
                  "title": "Select Key Pair"
                },
                {
                  "name": "platform",
                  "value": "non-windows",
                  "form_options": {
                    "type": "hidden"
                  },
                  "title": "Platform"
                },
                {
                  "name": "block_device_mappings",
                  "value": [
                    {
                      "DeviceName": "VOL-$$serviceowner-$$function##-V20-",
                      "Ebs.VolumeSize": "60",
                      "Ebs.VolumeType": "General Purpose (SSD)",
                      "Ebs.Iops": "100",
                      "devicepath": "/dev/sda1",
                      "Ebs.DeleteOnTermination": false,
                      "root_device": true,
                      "encrypted": false
                    },
                    {
                      "DeviceName": "VOL-$$serviceowner-$$function##-V20-",
                      "Ebs.VolumeSize": "1",
                      "Ebs.VolumeType": "General Purpose (SSD)",
                      "Ebs.Iops": "100",
                      "devicepath": "/dev/sdf",
                      "Ebs.DeleteOnTermination": false,
                      "root_device": false,
                      "encrypted": false
                    },
                    {
                      "DeviceName": "VOL-$$serviceowner-$$function##-V20-",
                      "Ebs.VolumeSize": "1",
                      "Ebs.VolumeType": "General Purpose (SSD)",
                      "Ebs.Iops": "100",
                      "devicepath": "/dev/sdg",
                      "Ebs.DeleteOnTermination": false,
                      "root_device": false,
                      "encrypted": false
                    }
                  ],
                  "form_options": {
                    "type": "block_device_mappings"
                  },
                  "title": "Block Device Mappings"
                },
                {
                  "name": "flavor_id",
                  "value": "t2.nano",
                  "form_options": {
                    "type": "select",
                    "options": [
                      "t2.nano",
                      "t2.micro",
                      "t2.small",
                      "t2.medium"
                    ]
                  },
                  "title": "Instance Type"
                },
                {
                  "name": "associate_public_ip",
                  "value": false,
                  "form_options": {
                    "type": "checkbox"
                  },
                  "title": "Associate Public IP"
                },
                {
                  "name": "instance_monitoring",
                  "form_options": {
                    "type": "checkbox"
                  },
                  "title": "Instance Monitoring"
                },
                {
                  "name": "original_block_device_mappings",
                  "value": [
                    {
                      "Ebs.VolumeSize": "60",
                      "Ebs.VolumeType": "gp2",
                      "Ebs.Iops": "180",
                      "Ebs.DeleteOnTermination": true,
                      "DevicePath": "/dev/sda1",
                      "Ebs.encrypted": false
                    },
                    {
                      "Ebs.VolumeSize": "1",
                      "Ebs.VolumeType": "gp2",
                      "Ebs.Iops": "3",
                      "Ebs.DeleteOnTermination": true,
                      "DevicePath": "/dev/sdf",
                      "Ebs.encrypted": false
                    },
                    {
                      "Ebs.VolumeSize": "1",
                      "Ebs.VolumeType": "gp2",
                      "Ebs.Iops": "3",
                      "Ebs.DeleteOnTermination": true,
                      "DevicePath": "/dev/sdg",
                      "Ebs.encrypted": false
                    }
                  ],
                  "form_options": {
                    "type": "hidden",
                    "readonly": "readonly"
                  },
                  "title": "Original Block Device Mappings"
                }
              ],
              "interfaces": [
                {
                  "id": "7fdcc014-422f-4c83-bf9e-dffbe4e34ab6",
                  "name": "default",
                  "interface_type": "Protocols::SecurityGroup",
                  "depends": true,
                  "connections": [
                    {
                      "id": "fa7a7776-1133-4999-bfec-418a0837e945",
                      "interface_id": "7fdcc014-422f-4c83-bf9e-dffbe4e34ab6",
                      "remote_interface_id": "94c76455-6f91-4128-8db1-d90b323cf891",
                      "internal": false
                    }
                  ]
                },
                {
                  "id": "927c5daf-d1cb-4eb7-bc0e-628f720e54a8",
                  "name": "launch-config",
                  "interface_type": "Protocols::AutoScalingConfiguration",
                  "depends": false,
                  "connections": []
                },
                {
                  "id": "a5f44153-0d77-4385-818b-5546d1546918",
                  "name": "prat",
                  "interface_type": "Protocols::Vpc",
                  "depends": true,
                  "connections": [
                    {
                      "id": "54c786ad-9343-4aa3-aba5-7344ba086bfd",
                      "interface_id": "a5f44153-0d77-4385-818b-5546d1546918",
                      "remote_interface_id": "cbb835cb-991c-4b69-a081-195c597025bf",
                      "internal": false
                    }
                  ]
                }
              ],
              "provides": [],
              "depends": []
            },
            {
              "id": "34984829-36c6-4ad2-b841-af33f8072865",
              "name": "VOL-$$serviceowner-$$function##-V20-",
              "type": "Services::Compute::Server::Volume::AWS",
              "geometry": {
                "x": "10",
                "y": "18",
                "width": "56",
                "height": "56"
              },
              "internal": true,
              "additional_properties": {
                "id": "34984829-36c6-4ad2-b841-af33f8072865",
                "numbered": false,
                "edge": false,
                "service_type": "volume",
                "generic_type": "Services::Compute::Server::Volume",
                "type": "Services::Compute::Server::Volume::AWS",
                "name": "VOL-$$serviceowner-$$function##-V20-",
                "internal": true,
                "drawable": false,
                "draggable": true,
                "interfaces": [
                  {
                    "id": "8ba53d8e-82e0-4c10-ba36-5457c38e65a1",
                    "name": "volume",
                    "depends": false,
                    "connections": null,
                    "interface_type": "Protocols::Disk"
                  },
                  {
                    "id": "60c09279-7fca-4203-a74e-2fb8b7b0b6f9",
                    "name": "availability_zone1",
                    "depends": true,
                    "connections": [
                      {
                        "id": "d84c8429-b093-456b-9b7e-735b034179ef",
                        "interface_id": "60c09279-7fca-4203-a74e-2fb8b7b0b6f9",
                        "remote_interface_id": "f1629909-7efa-432b-9844-65af8bfc84c2",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::AvailabilityZone"
                  }
                ],
                "vpc_id": "16ef4f23-56e2-4f45-a2e3-f20e907bbfcf",
                "primary_key": "",
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
                "depends": null,
                "parent_id": "4e621aa6-6a4d-4676-ae41-2b2bd5bb3647",
                "properties": [
                  {
                    "name": "size",
                    "value": "4",
                    "form_options": {
                      "type": "range",
                      "min": "1",
                      "max": "16384",
                      "step": "1"
                    },
                    "title": "Size"
                  },
                  {
                    "name": "volume_type",
                    "value": "General Purpose (SSD)",
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
                    "value": "/dev/sdh",
                    "form_options": {
                      "type": "text"
                    },
                    "title": "Device Path"
                  },
                  {
                    "name": "delete_on_termination",
                    "value": false,
                    "form_options": {
                      "type": "checkbox"
                    },
                    "title": "Delete On Termination"
                  },
                  {
                    "name": "root_device",
                    "value": false,
                    "form_options": {
                      "type": "hidden",
                      "readonly": "readonly"
                    },
                    "title": "Root Device"
                  },
                  {
                    "name": "configured_with_ami",
                    "value": false,
                    "form_options": {
                      "type": "hidden",
                      "readonly": "readonly"
                    },
                    "title": "Configured With AMI"
                  },
                  {
                    "name": "encrypted",
                    "value": false,
                    "form_options": {
                      "type": "checkbox",
                      "required": false
                    },
                    "title": "Encrypted"
                  },
                  {
                    "name": "create_from_snapshot",
                    "value": false,
                    "form_options": {
                      "environment_with_snapshots_exists": false,
                      "unallocated_snapshots_exists": false,
                      "type": "checkbox"
                    },
                    "title": "Create From Snapshot"
                  },
                  {
                    "name": "snapshot_id",
                    "value": "",
                    "form_options": {
                      "type": "select",
                      "options": []
                    },
                    "title": "Snapshot ID"
                  },
                  {
                    "name": "snapshot_provider_id",
                    "value": "",
                    "form_options": {
                      "type": "hidden",
                      "readonly": "readonly"
                    },
                    "title": "Snapshot Provider ID"
                  },
                  {
                    "name": "snapshot_environment_id",
                    "value": "",
                    "form_options": {
                      "type": "select",
                      "options": []
                    },
                    "title": "Snapshot Environment ID"
                  }
                ]
              },
              "properties": [
                {
                  "name": "size",
                  "value": "4",
                  "form_options": {
                    "type": "range",
                    "min": "1",
                    "max": "16384",
                    "step": "1"
                  },
                  "title": "Size"
                },
                {
                  "name": "volume_type",
                  "value": "General Purpose (SSD)",
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
                  "value": "/dev/sdh",
                  "form_options": {
                    "type": "text"
                  },
                  "title": "Device Path"
                },
                {
                  "name": "delete_on_termination",
                  "value": false,
                  "form_options": {
                    "type": "checkbox"
                  },
                  "title": "Delete On Termination"
                },
                {
                  "name": "root_device",
                  "value": false,
                  "form_options": {
                    "type": "hidden",
                    "readonly": "readonly"
                  },
                  "title": "Root Device"
                },
                {
                  "name": "configured_with_ami",
                  "value": false,
                  "form_options": {
                    "type": "hidden",
                    "readonly": "readonly"
                  },
                  "title": "Configured With AMI"
                },
                {
                  "name": "encrypted",
                  "value": false,
                  "form_options": {
                    "type": "checkbox",
                    "required": false
                  },
                  "title": "Encrypted"
                },
                {
                  "name": "create_from_snapshot",
                  "value": false,
                  "form_options": {
                    "environment_with_snapshots_exists": false,
                    "unallocated_snapshots_exists": false,
                    "type": "checkbox"
                  },
                  "title": "Create From Snapshot"
                },
                {
                  "name": "snapshot_id",
                  "value": "",
                  "form_options": {
                    "type": "select",
                    "options": []
                  },
                  "title": "Snapshot ID"
                },
                {
                  "name": "snapshot_provider_id",
                  "value": "",
                  "form_options": {
                    "type": "hidden",
                    "readonly": "readonly"
                  },
                  "title": "Snapshot Provider ID"
                },
                {
                  "name": "snapshot_environment_id",
                  "value": "",
                  "form_options": {
                    "type": "select",
                    "options": []
                  },
                  "title": "Snapshot Environment ID"
                }
              ],
              "interfaces": [
                {
                  "id": "8ba53d8e-82e0-4c10-ba36-5457c38e65a1",
                  "name": "volume",
                  "interface_type": "Protocols::Disk",
                  "depends": false,
                  "connections": []
                },
                {
                  "id": "60c09279-7fca-4203-a74e-2fb8b7b0b6f9",
                  "name": "availability_zone1",
                  "interface_type": "Protocols::AvailabilityZone",
                  "depends": true,
                  "connections": [
                    {
                      "id": "d84c8429-b093-456b-9b7e-735b034179ef",
                      "interface_id": "60c09279-7fca-4203-a74e-2fb8b7b0b6f9",
                      "remote_interface_id": "f1629909-7efa-432b-9844-65af8bfc84c2",
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
              "id": "71359158-e572-494e-9ff0-1ee2b3d10e98",
              "name": "VOL-$$serviceowner-$$function##-V20-",
              "type": "Services::Compute::Server::Volume::AWS",
              "geometry": {
                "x": "10",
                "y": "18",
                "width": "56",
                "height": "56"
              },
              "internal": true,
              "additional_properties": {
                "id": "71359158-e572-494e-9ff0-1ee2b3d10e98",
                "numbered": false,
                "edge": false,
                "service_type": "volume",
                "generic_type": "Services::Compute::Server::Volume",
                "type": "Services::Compute::Server::Volume::AWS",
                "name": "VOL-$$serviceowner-$$function##-V20-",
                "internal": true,
                "drawable": false,
                "draggable": true,
                "interfaces": [
                  {
                    "id": "dc580866-77d5-4643-90a0-31254a642215",
                    "name": "volume",
                    "depends": false,
                    "connections": null,
                    "interface_type": "Protocols::Disk"
                  },
                  {
                    "id": "b0d245fd-d158-4447-b826-56165584dd37",
                    "name": "availability_zone1",
                    "depends": true,
                    "connections": [
                      {
                        "id": "096c85e6-833c-4abb-9a00-5038062ee2fd",
                        "interface_id": "b0d245fd-d158-4447-b826-56165584dd37",
                        "remote_interface_id": "f1629909-7efa-432b-9844-65af8bfc84c2",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::AvailabilityZone"
                  }
                ],
                "vpc_id": "16ef4f23-56e2-4f45-a2e3-f20e907bbfcf",
                "primary_key": "",
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
                "depends": null,
                "parent_id": "4e621aa6-6a4d-4676-ae41-2b2bd5bb3647",
                "properties": [
                  {
                    "name": "size",
                    "value": "4",
                    "form_options": {
                      "type": "range",
                      "min": "1",
                      "max": "16384",
                      "step": "1"
                    },
                    "title": "Size"
                  },
                  {
                    "name": "volume_type",
                    "value": "General Purpose (SSD)",
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
                    "value": "/dev/sdi",
                    "form_options": {
                      "type": "text"
                    },
                    "title": "Device Path"
                  },
                  {
                    "name": "delete_on_termination",
                    "value": false,
                    "form_options": {
                      "type": "checkbox"
                    },
                    "title": "Delete On Termination"
                  },
                  {
                    "name": "root_device",
                    "value": false,
                    "form_options": {
                      "type": "hidden",
                      "readonly": "readonly"
                    },
                    "title": "Root Device"
                  },
                  {
                    "name": "configured_with_ami",
                    "value": false,
                    "form_options": {
                      "type": "hidden",
                      "readonly": "readonly"
                    },
                    "title": "Configured With AMI"
                  },
                  {
                    "name": "encrypted",
                    "value": false,
                    "form_options": {
                      "type": "checkbox",
                      "required": false
                    },
                    "title": "Encrypted"
                  },
                  {
                    "name": "create_from_snapshot",
                    "value": false,
                    "form_options": {
                      "environment_with_snapshots_exists": false,
                      "unallocated_snapshots_exists": false,
                      "type": "checkbox"
                    },
                    "title": "Create From Snapshot"
                  },
                  {
                    "name": "snapshot_id",
                    "value": "",
                    "form_options": {
                      "type": "select",
                      "options": []
                    },
                    "title": "Snapshot ID"
                  },
                  {
                    "name": "snapshot_provider_id",
                    "value": "",
                    "form_options": {
                      "type": "hidden",
                      "readonly": "readonly"
                    },
                    "title": "Snapshot Provider ID"
                  },
                  {
                    "name": "snapshot_environment_id",
                    "value": "",
                    "form_options": {
                      "type": "select",
                      "options": []
                    },
                    "title": "Snapshot Environment ID"
                  }
                ]
              },
              "properties": [
                {
                  "name": "size",
                  "value": "4",
                  "form_options": {
                    "type": "range",
                    "min": "1",
                    "max": "16384",
                    "step": "1"
                  },
                  "title": "Size"
                },
                {
                  "name": "volume_type",
                  "value": "General Purpose (SSD)",
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
                  "value": "/dev/sdi",
                  "form_options": {
                    "type": "text"
                  },
                  "title": "Device Path"
                },
                {
                  "name": "delete_on_termination",
                  "value": false,
                  "form_options": {
                    "type": "checkbox"
                  },
                  "title": "Delete On Termination"
                },
                {
                  "name": "root_device",
                  "value": false,
                  "form_options": {
                    "type": "hidden",
                    "readonly": "readonly"
                  },
                  "title": "Root Device"
                },
                {
                  "name": "configured_with_ami",
                  "value": false,
                  "form_options": {
                    "type": "hidden",
                    "readonly": "readonly"
                  },
                  "title": "Configured With AMI"
                },
                {
                  "name": "encrypted",
                  "value": false,
                  "form_options": {
                    "type": "checkbox",
                    "required": false
                  },
                  "title": "Encrypted"
                },
                {
                  "name": "create_from_snapshot",
                  "value": false,
                  "form_options": {
                    "environment_with_snapshots_exists": false,
                    "unallocated_snapshots_exists": false,
                    "type": "checkbox"
                  },
                  "title": "Create From Snapshot"
                },
                {
                  "name": "snapshot_id",
                  "value": "",
                  "form_options": {
                    "type": "select",
                    "options": []
                  },
                  "title": "Snapshot ID"
                },
                {
                  "name": "snapshot_provider_id",
                  "value": "",
                  "form_options": {
                    "type": "hidden",
                    "readonly": "readonly"
                  },
                  "title": "Snapshot Provider ID"
                },
                {
                  "name": "snapshot_environment_id",
                  "value": "",
                  "form_options": {
                    "type": "select",
                    "options": []
                  },
                  "title": "Snapshot Environment ID"
                }
              ],
              "interfaces": [
                {
                  "id": "dc580866-77d5-4643-90a0-31254a642215",
                  "name": "volume",
                  "interface_type": "Protocols::Disk",
                  "depends": false,
                  "connections": []
                },
                {
                  "id": "b0d245fd-d158-4447-b826-56165584dd37",
                  "name": "availability_zone1",
                  "interface_type": "Protocols::AvailabilityZone",
                  "depends": true,
                  "connections": [
                    {
                      "id": "096c85e6-833c-4abb-9a00-5038062ee2fd",
                      "interface_id": "b0d245fd-d158-4447-b826-56165584dd37",
                      "remote_interface_id": "f1629909-7efa-432b-9844-65af8bfc84c2",
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
              "id": "4853a4a0-6608-45be-b16c-25760ed21f52",
              "name": "VOL-$$serviceowner-$$function##-V20-",
              "type": "Services::Compute::Server::Volume::AWS",
              "geometry": {
                "x": "10",
                "y": "21",
                "width": "25",
                "height": "25"
              },
              "internal": true,
              "additional_properties": {
                "id": "4853a4a0-6608-45be-b16c-25760ed21f52",
                "numbered": false,
                "edge": false,
                "service_type": "Volume",
                "generic_type": "Services::Compute::Server::Volume",
                "type": "Services::Compute::Server::Volume::AWS",
                "name": "VOL-$$serviceowner-$$function##-V20-",
                "internal": true,
                "drawable": false,
                "draggable": true,
                "interfaces": [
                  {
                    "id": "6eff111e-1b94-4d8a-b641-156187d67b27",
                    "name": "volume",
                    "depends": false,
                    "connections": null,
                    "interface_type": "Protocols::Disk"
                  },
                  {
                    "id": "af071555-7df9-4ba7-b481-665c240545f5",
                    "name": "availability_zone1",
                    "depends": true,
                    "connections": [
                      {
                        "id": "bde421d8-05ce-4675-9753-f2fa9825c88c",
                        "interface_id": "af071555-7df9-4ba7-b481-665c240545f5",
                        "remote_interface_id": "9067b337-c820-4315-8099-413cddc3d083",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::AvailabilityZone"
                  }
                ],
                "vpc_id": "16ef4f23-56e2-4f45-a2e3-f20e907bbfcf",
                "primary_key": "",
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
                "depends": null,
                "parent_id": "7bc345f2-d337-46ff-843f-55d54d3f91fc",
                "properties": [
                  {
                    "name": "size",
                    "value": "1",
                    "form_options": {
                      "type": "range",
                      "min": "1",
                      "max": "16384",
                      "step": "1"
                    },
                    "title": "Size"
                  },
                  {
                    "name": "volume_type",
                    "value": "General Purpose (SSD)",
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
                    "value": "/dev/sdf",
                    "form_options": {
                      "type": "text"
                    },
                    "title": "Device Path"
                  },
                  {
                    "name": "delete_on_termination",
                    "value": false,
                    "form_options": {
                      "type": "checkbox"
                    },
                    "title": "Delete On Termination"
                  },
                  {
                    "name": "root_device",
                    "value": false,
                    "form_options": {
                      "type": "hidden",
                      "readonly": "readonly"
                    },
                    "title": "Root Device"
                  },
                  {
                    "name": "configured_with_ami",
                    "value": true,
                    "form_options": {
                      "type": "hidden",
                      "readonly": "readonly"
                    },
                    "title": "Configured With AMI"
                  },
                  {
                    "name": "encrypted",
                    "value": false,
                    "form_options": {
                      "type": "checkbox",
                      "required": false
                    },
                    "title": "Encrypted"
                  },
                  {
                    "name": "create_from_snapshot",
                    "value": false,
                    "form_options": {
                      "environment_with_snapshots_exists": false,
                      "unallocated_snapshots_exists": false,
                      "type": "checkbox"
                    },
                    "title": "Create From Snapshot"
                  },
                  {
                    "name": "snapshot_id",
                    "value": "",
                    "form_options": {
                      "type": "select",
                      "options": []
                    },
                    "title": "Snapshot ID"
                  },
                  {
                    "name": "snapshot_provider_id",
                    "value": "",
                    "form_options": {
                      "type": "hidden",
                      "readonly": "readonly"
                    },
                    "title": "Snapshot Provider ID"
                  },
                  {
                    "name": "snapshot_environment_id",
                    "value": "",
                    "form_options": {
                      "type": "select",
                      "options": []
                    },
                    "title": "Snapshot Environment ID"
                  }
                ]
              },
              "properties": [
                {
                  "name": "size",
                  "value": "1",
                  "form_options": {
                    "type": "range",
                    "min": "1",
                    "max": "16384",
                    "step": "1"
                  },
                  "title": "Size"
                },
                {
                  "name": "volume_type",
                  "value": "General Purpose (SSD)",
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
                  "value": "/dev/sdf",
                  "form_options": {
                    "type": "text"
                  },
                  "title": "Device Path"
                },
                {
                  "name": "delete_on_termination",
                  "value": false,
                  "form_options": {
                    "type": "checkbox"
                  },
                  "title": "Delete On Termination"
                },
                {
                  "name": "root_device",
                  "value": false,
                  "form_options": {
                    "type": "hidden",
                    "readonly": "readonly"
                  },
                  "title": "Root Device"
                },
                {
                  "name": "configured_with_ami",
                  "value": true,
                  "form_options": {
                    "type": "hidden",
                    "readonly": "readonly"
                  },
                  "title": "Configured With AMI"
                },
                {
                  "name": "encrypted",
                  "value": false,
                  "form_options": {
                    "type": "checkbox",
                    "required": false
                  },
                  "title": "Encrypted"
                },
                {
                  "name": "create_from_snapshot",
                  "value": false,
                  "form_options": {
                    "environment_with_snapshots_exists": false,
                    "unallocated_snapshots_exists": false,
                    "type": "checkbox"
                  },
                  "title": "Create From Snapshot"
                },
                {
                  "name": "snapshot_id",
                  "value": "",
                  "form_options": {
                    "type": "select",
                    "options": []
                  },
                  "title": "Snapshot ID"
                },
                {
                  "name": "snapshot_provider_id",
                  "value": "",
                  "form_options": {
                    "type": "hidden",
                    "readonly": "readonly"
                  },
                  "title": "Snapshot Provider ID"
                },
                {
                  "name": "snapshot_environment_id",
                  "value": "",
                  "form_options": {
                    "type": "select",
                    "options": []
                  },
                  "title": "Snapshot Environment ID"
                }
              ],
              "interfaces": [
                {
                  "id": "6eff111e-1b94-4d8a-b641-156187d67b27",
                  "name": "volume",
                  "interface_type": "Protocols::Disk",
                  "depends": false,
                  "connections": []
                },
                {
                  "id": "af071555-7df9-4ba7-b481-665c240545f5",
                  "name": "availability_zone1",
                  "interface_type": "Protocols::AvailabilityZone",
                  "depends": true,
                  "connections": [
                    {
                      "id": "bde421d8-05ce-4675-9753-f2fa9825c88c",
                      "interface_id": "af071555-7df9-4ba7-b481-665c240545f5",
                      "remote_interface_id": "9067b337-c820-4315-8099-413cddc3d083",
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
              "id": "ae2e7eae-21d1-430d-8bb2-4cf9aab79628",
              "name": "VOL-$$serviceowner-$$function##-V20-",
              "type": "Services::Compute::Server::Volume::AWS",
              "geometry": {
                "x": "10",
                "y": "21",
                "width": "25",
                "height": "25"
              },
              "internal": true,
              "additional_properties": {
                "id": "ae2e7eae-21d1-430d-8bb2-4cf9aab79628",
                "numbered": false,
                "edge": false,
                "service_type": "Volume",
                "generic_type": "Services::Compute::Server::Volume",
                "type": "Services::Compute::Server::Volume::AWS",
                "name": "VOL-$$serviceowner-$$function##-V20-",
                "internal": true,
                "drawable": false,
                "draggable": true,
                "interfaces": [
                  {
                    "id": "b92d66d6-8929-41f6-8e24-107214a083a1",
                    "name": "volume",
                    "depends": false,
                    "connections": null,
                    "interface_type": "Protocols::Disk"
                  },
                  {
                    "id": "401a9e9c-cac0-45b5-8fc5-90a9c9ccf912",
                    "name": "availability_zone1",
                    "depends": true,
                    "connections": [
                      {
                        "id": "5e002f55-f832-44ee-a7a7-207c95062c57",
                        "interface_id": "401a9e9c-cac0-45b5-8fc5-90a9c9ccf912",
                        "remote_interface_id": "f1629909-7efa-432b-9844-65af8bfc84c2",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::AvailabilityZone"
                  }
                ],
                "vpc_id": "16ef4f23-56e2-4f45-a2e3-f20e907bbfcf",
                "primary_key": "",
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
                "depends": null,
                "parent_id": "4e621aa6-6a4d-4676-ae41-2b2bd5bb3647",
                "properties": [
                  {
                    "name": "size",
                    "value": "1",
                    "form_options": {
                      "type": "range",
                      "min": "1",
                      "max": "16384",
                      "step": "1"
                    },
                    "title": "Size"
                  },
                  {
                    "name": "volume_type",
                    "value": "General Purpose (SSD)",
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
                    "value": "/dev/sdg",
                    "form_options": {
                      "type": "text"
                    },
                    "title": "Device Path"
                  },
                  {
                    "name": "delete_on_termination",
                    "value": false,
                    "form_options": {
                      "type": "checkbox"
                    },
                    "title": "Delete On Termination"
                  },
                  {
                    "name": "root_device",
                    "value": false,
                    "form_options": {
                      "type": "hidden",
                      "readonly": "readonly"
                    },
                    "title": "Root Device"
                  },
                  {
                    "name": "configured_with_ami",
                    "value": true,
                    "form_options": {
                      "type": "hidden",
                      "readonly": "readonly"
                    },
                    "title": "Configured With AMI"
                  },
                  {
                    "name": "encrypted",
                    "value": false,
                    "form_options": {
                      "type": "checkbox",
                      "required": false
                    },
                    "title": "Encrypted"
                  },
                  {
                    "name": "create_from_snapshot",
                    "value": false,
                    "form_options": {
                      "environment_with_snapshots_exists": false,
                      "unallocated_snapshots_exists": false,
                      "type": "checkbox"
                    },
                    "title": "Create From Snapshot"
                  },
                  {
                    "name": "snapshot_id",
                    "value": "",
                    "form_options": {
                      "type": "select",
                      "options": []
                    },
                    "title": "Snapshot ID"
                  },
                  {
                    "name": "snapshot_provider_id",
                    "value": "",
                    "form_options": {
                      "type": "hidden",
                      "readonly": "readonly"
                    },
                    "title": "Snapshot Provider ID"
                  },
                  {
                    "name": "snapshot_environment_id",
                    "value": "",
                    "form_options": {
                      "type": "select",
                      "options": []
                    },
                    "title": "Snapshot Environment ID"
                  }
                ]
              },
              "properties": [
                {
                  "name": "size",
                  "value": "1",
                  "form_options": {
                    "type": "range",
                    "min": "1",
                    "max": "16384",
                    "step": "1"
                  },
                  "title": "Size"
                },
                {
                  "name": "volume_type",
                  "value": "General Purpose (SSD)",
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
                  "value": "/dev/sdg",
                  "form_options": {
                    "type": "text"
                  },
                  "title": "Device Path"
                },
                {
                  "name": "delete_on_termination",
                  "value": false,
                  "form_options": {
                    "type": "checkbox"
                  },
                  "title": "Delete On Termination"
                },
                {
                  "name": "root_device",
                  "value": false,
                  "form_options": {
                    "type": "hidden",
                    "readonly": "readonly"
                  },
                  "title": "Root Device"
                },
                {
                  "name": "configured_with_ami",
                  "value": true,
                  "form_options": {
                    "type": "hidden",
                    "readonly": "readonly"
                  },
                  "title": "Configured With AMI"
                },
                {
                  "name": "encrypted",
                  "value": false,
                  "form_options": {
                    "type": "checkbox",
                    "required": false
                  },
                  "title": "Encrypted"
                },
                {
                  "name": "create_from_snapshot",
                  "value": false,
                  "form_options": {
                    "environment_with_snapshots_exists": false,
                    "unallocated_snapshots_exists": false,
                    "type": "checkbox"
                  },
                  "title": "Create From Snapshot"
                },
                {
                  "name": "snapshot_id",
                  "value": "",
                  "form_options": {
                    "type": "select",
                    "options": []
                  },
                  "title": "Snapshot ID"
                },
                {
                  "name": "snapshot_provider_id",
                  "value": "",
                  "form_options": {
                    "type": "hidden",
                    "readonly": "readonly"
                  },
                  "title": "Snapshot Provider ID"
                },
                {
                  "name": "snapshot_environment_id",
                  "value": "",
                  "form_options": {
                    "type": "select",
                    "options": []
                  },
                  "title": "Snapshot Environment ID"
                }
              ],
              "interfaces": [
                {
                  "id": "b92d66d6-8929-41f6-8e24-107214a083a1",
                  "name": "volume",
                  "interface_type": "Protocols::Disk",
                  "depends": false,
                  "connections": []
                },
                {
                  "id": "401a9e9c-cac0-45b5-8fc5-90a9c9ccf912",
                  "name": "availability_zone1",
                  "interface_type": "Protocols::AvailabilityZone",
                  "depends": true,
                  "connections": [
                    {
                      "id": "5e002f55-f832-44ee-a7a7-207c95062c57",
                      "interface_id": "401a9e9c-cac0-45b5-8fc5-90a9c9ccf912",
                      "remote_interface_id": "f1629909-7efa-432b-9844-65af8bfc84c2",
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
              "id": "a623e868-57ff-4885-9db8-132bf3b2c460",
              "name": "VOL-$$serviceowner-$$function##-V20-",
              "type": "Services::Compute::Server::Volume::AWS",
              "geometry": {
                "x": "10",
                "y": "21",
                "width": "25",
                "height": "25"
              },
              "internal": true,
              "additional_properties": {
                "id": "a623e868-57ff-4885-9db8-132bf3b2c460",
                "numbered": false,
                "edge": false,
                "service_type": "Volume",
                "generic_type": "Services::Compute::Server::Volume",
                "type": "Services::Compute::Server::Volume::AWS",
                "name": "VOL-$$serviceowner-$$function##-V20-",
                "internal": true,
                "drawable": false,
                "draggable": true,
                "interfaces": [
                  {
                    "id": "7be345e1-309a-459f-ac24-6fcff988502c",
                    "name": "volume",
                    "depends": false,
                    "connections": null,
                    "interface_type": "Protocols::Disk"
                  },
                  {
                    "id": "1f4b6da4-a99c-4447-9071-d61c905b694b",
                    "name": "availability_zone1",
                    "depends": true,
                    "connections": [
                      {
                        "id": "96443fec-7304-40b4-808c-1969323c34f1",
                        "interface_id": "1f4b6da4-a99c-4447-9071-d61c905b694b",
                        "remote_interface_id": "f1629909-7efa-432b-9844-65af8bfc84c2",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::AvailabilityZone"
                  }
                ],
                "vpc_id": "16ef4f23-56e2-4f45-a2e3-f20e907bbfcf",
                "primary_key": "",
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
                "depends": null,
                "parent_id": "4e621aa6-6a4d-4676-ae41-2b2bd5bb3647",
                "properties": [
                  {
                    "name": "size",
                    "value": "1",
                    "form_options": {
                      "type": "range",
                      "min": "1",
                      "max": "16384",
                      "step": "1"
                    },
                    "title": "Size"
                  },
                  {
                    "name": "volume_type",
                    "value": "General Purpose (SSD)",
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
                    "value": "/dev/sdf",
                    "form_options": {
                      "type": "text"
                    },
                    "title": "Device Path"
                  },
                  {
                    "name": "delete_on_termination",
                    "value": false,
                    "form_options": {
                      "type": "checkbox"
                    },
                    "title": "Delete On Termination"
                  },
                  {
                    "name": "root_device",
                    "value": false,
                    "form_options": {
                      "type": "hidden",
                      "readonly": "readonly"
                    },
                    "title": "Root Device"
                  },
                  {
                    "name": "configured_with_ami",
                    "value": true,
                    "form_options": {
                      "type": "hidden",
                      "readonly": "readonly"
                    },
                    "title": "Configured With AMI"
                  },
                  {
                    "name": "encrypted",
                    "value": false,
                    "form_options": {
                      "type": "checkbox",
                      "required": false
                    },
                    "title": "Encrypted"
                  },
                  {
                    "name": "create_from_snapshot",
                    "value": false,
                    "form_options": {
                      "environment_with_snapshots_exists": false,
                      "unallocated_snapshots_exists": false,
                      "type": "checkbox"
                    },
                    "title": "Create From Snapshot"
                  },
                  {
                    "name": "snapshot_id",
                    "value": "",
                    "form_options": {
                      "type": "select",
                      "options": []
                    },
                    "title": "Snapshot ID"
                  },
                  {
                    "name": "snapshot_provider_id",
                    "value": "",
                    "form_options": {
                      "type": "hidden",
                      "readonly": "readonly"
                    },
                    "title": "Snapshot Provider ID"
                  },
                  {
                    "name": "snapshot_environment_id",
                    "value": "",
                    "form_options": {
                      "type": "select",
                      "options": []
                    },
                    "title": "Snapshot Environment ID"
                  }
                ]
              },
              "properties": [
                {
                  "name": "size",
                  "value": "1",
                  "form_options": {
                    "type": "range",
                    "min": "1",
                    "max": "16384",
                    "step": "1"
                  },
                  "title": "Size"
                },
                {
                  "name": "volume_type",
                  "value": "General Purpose (SSD)",
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
                  "value": "/dev/sdf",
                  "form_options": {
                    "type": "text"
                  },
                  "title": "Device Path"
                },
                {
                  "name": "delete_on_termination",
                  "value": false,
                  "form_options": {
                    "type": "checkbox"
                  },
                  "title": "Delete On Termination"
                },
                {
                  "name": "root_device",
                  "value": false,
                  "form_options": {
                    "type": "hidden",
                    "readonly": "readonly"
                  },
                  "title": "Root Device"
                },
                {
                  "name": "configured_with_ami",
                  "value": true,
                  "form_options": {
                    "type": "hidden",
                    "readonly": "readonly"
                  },
                  "title": "Configured With AMI"
                },
                {
                  "name": "encrypted",
                  "value": false,
                  "form_options": {
                    "type": "checkbox",
                    "required": false
                  },
                  "title": "Encrypted"
                },
                {
                  "name": "create_from_snapshot",
                  "value": false,
                  "form_options": {
                    "environment_with_snapshots_exists": false,
                    "unallocated_snapshots_exists": false,
                    "type": "checkbox"
                  },
                  "title": "Create From Snapshot"
                },
                {
                  "name": "snapshot_id",
                  "value": "",
                  "form_options": {
                    "type": "select",
                    "options": []
                  },
                  "title": "Snapshot ID"
                },
                {
                  "name": "snapshot_provider_id",
                  "value": "",
                  "form_options": {
                    "type": "hidden",
                    "readonly": "readonly"
                  },
                  "title": "Snapshot Provider ID"
                },
                {
                  "name": "snapshot_environment_id",
                  "value": "",
                  "form_options": {
                    "type": "select",
                    "options": []
                  },
                  "title": "Snapshot Environment ID"
                }
              ],
              "interfaces": [
                {
                  "id": "7be345e1-309a-459f-ac24-6fcff988502c",
                  "name": "volume",
                  "interface_type": "Protocols::Disk",
                  "depends": false,
                  "connections": []
                },
                {
                  "id": "1f4b6da4-a99c-4447-9071-d61c905b694b",
                  "name": "availability_zone1",
                  "interface_type": "Protocols::AvailabilityZone",
                  "depends": true,
                  "connections": [
                    {
                      "id": "96443fec-7304-40b4-808c-1969323c34f1",
                      "interface_id": "1f4b6da4-a99c-4447-9071-d61c905b694b",
                      "remote_interface_id": "f1629909-7efa-432b-9844-65af8bfc84c2",
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
              "id": "0713d88d-afa9-4c2c-937f-df14603cfc13",
              "name": "VOL-$$serviceowner-$$function##-V20-",
              "type": "Services::Compute::Server::Volume::AWS",
              "geometry": {
                "x": "10",
                "y": "21",
                "width": "25",
                "height": "25"
              },
              "internal": true,
              "additional_properties": {
                "id": "0713d88d-afa9-4c2c-937f-df14603cfc13",
                "numbered": false,
                "edge": false,
                "service_type": "Volume",
                "generic_type": "Services::Compute::Server::Volume",
                "type": "Services::Compute::Server::Volume::AWS",
                "name": "VOL-$$serviceowner-$$function##-V20-",
                "internal": true,
                "drawable": false,
                "draggable": true,
                "interfaces": [
                  {
                    "id": "cbc0f40d-5818-4597-896c-be4c970c8433",
                    "name": "volume",
                    "depends": false,
                    "connections": null,
                    "interface_type": "Protocols::Disk"
                  },
                  {
                    "id": "1071fcf9-6046-442b-9edf-3ec4c2fcdaf5",
                    "name": "availability_zone1",
                    "depends": true,
                    "connections": [
                      {
                        "id": "f2ba506e-d054-4d2a-aef9-973dd9ddda18",
                        "interface_id": "1071fcf9-6046-442b-9edf-3ec4c2fcdaf5",
                        "remote_interface_id": "f1629909-7efa-432b-9844-65af8bfc84c2",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::AvailabilityZone"
                  }
                ],
                "vpc_id": "16ef4f23-56e2-4f45-a2e3-f20e907bbfcf",
                "primary_key": "",
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
                "depends": null,
                "parent_id": "4e621aa6-6a4d-4676-ae41-2b2bd5bb3647",
                "properties": [
                  {
                    "name": "size",
                    "value": "60",
                    "form_options": {
                      "type": "range",
                      "min": "1",
                      "max": "16384",
                      "step": "1"
                    },
                    "title": "Size"
                  },
                  {
                    "name": "volume_type",
                    "value": "General Purpose (SSD)",
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
                    "value": "/dev/sda1",
                    "form_options": {
                      "type": "text"
                    },
                    "title": "Device Path"
                  },
                  {
                    "name": "delete_on_termination",
                    "value": false,
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
                    "value": true,
                    "form_options": {
                      "type": "hidden",
                      "readonly": "readonly"
                    },
                    "title": "Configured With AMI"
                  },
                  {
                    "name": "create_from_snapshot",
                    "value": false,
                    "form_options": {
                      "environment_with_snapshots_exists": false,
                      "unallocated_snapshots_exists": false,
                      "type": "checkbox"
                    },
                    "title": "Create From Snapshot"
                  },
                  {
                    "name": "snapshot_id",
                    "value": "",
                    "form_options": {
                      "type": "select",
                      "options": []
                    },
                    "title": "Snapshot ID"
                  },
                  {
                    "name": "snapshot_provider_id",
                    "value": "",
                    "form_options": {
                      "type": "hidden",
                      "readonly": "readonly"
                    },
                    "title": "Snapshot Provider ID"
                  },
                  {
                    "name": "snapshot_environment_id",
                    "value": "",
                    "form_options": {
                      "type": "select",
                      "options": []
                    },
                    "title": "Snapshot Environment ID"
                  }
                ]
              },
              "properties": [
                {
                  "name": "size",
                  "value": "60",
                  "form_options": {
                    "type": "range",
                    "min": "1",
                    "max": "16384",
                    "step": "1"
                  },
                  "title": "Size"
                },
                {
                  "name": "volume_type",
                  "value": "General Purpose (SSD)",
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
                  "value": "/dev/sda1",
                  "form_options": {
                    "type": "text"
                  },
                  "title": "Device Path"
                },
                {
                  "name": "delete_on_termination",
                  "value": false,
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
                  "value": true,
                  "form_options": {
                    "type": "hidden",
                    "readonly": "readonly"
                  },
                  "title": "Configured With AMI"
                },
                {
                  "name": "create_from_snapshot",
                  "value": false,
                  "form_options": {
                    "environment_with_snapshots_exists": false,
                    "unallocated_snapshots_exists": false,
                    "type": "checkbox"
                  },
                  "title": "Create From Snapshot"
                },
                {
                  "name": "snapshot_id",
                  "value": "",
                  "form_options": {
                    "type": "select",
                    "options": []
                  },
                  "title": "Snapshot ID"
                },
                {
                  "name": "snapshot_provider_id",
                  "value": "",
                  "form_options": {
                    "type": "hidden",
                    "readonly": "readonly"
                  },
                  "title": "Snapshot Provider ID"
                },
                {
                  "name": "snapshot_environment_id",
                  "value": "",
                  "form_options": {
                    "type": "select",
                    "options": []
                  },
                  "title": "Snapshot Environment ID"
                }
              ],
              "interfaces": [
                {
                  "id": "cbc0f40d-5818-4597-896c-be4c970c8433",
                  "name": "volume",
                  "interface_type": "Protocols::Disk",
                  "depends": false,
                  "connections": []
                },
                {
                  "id": "1071fcf9-6046-442b-9edf-3ec4c2fcdaf5",
                  "name": "availability_zone1",
                  "interface_type": "Protocols::AvailabilityZone",
                  "depends": true,
                  "connections": [
                    {
                      "id": "f2ba506e-d054-4d2a-aef9-973dd9ddda18",
                      "interface_id": "1071fcf9-6046-442b-9edf-3ec4c2fcdaf5",
                      "remote_interface_id": "f1629909-7efa-432b-9844-65af8bfc84c2",
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
              "id": "d83e4414-5fe2-4ab9-a940-25d2c2b18b32",
              "name": "VOL-$$serviceowner-$$function##-V20-",
              "type": "Services::Compute::Server::Volume::AWS",
              "geometry": {
                "x": "10",
                "y": "21",
                "width": "25",
                "height": "25"
              },
              "internal": true,
              "additional_properties": {
                "id": "d83e4414-5fe2-4ab9-a940-25d2c2b18b32",
                "numbered": false,
                "edge": false,
                "service_type": "Volume",
                "generic_type": "Services::Compute::Server::Volume",
                "type": "Services::Compute::Server::Volume::AWS",
                "name": "VOL-$$serviceowner-$$function##-V20-",
                "internal": true,
                "drawable": false,
                "draggable": true,
                "interfaces": [
                  {
                    "id": "00b8306b-5256-4a02-8c86-75a54f959ab5",
                    "name": "volume",
                    "depends": false,
                    "connections": null,
                    "interface_type": "Protocols::Disk"
                  },
                  {
                    "id": "5246d855-2b0d-40e7-bfc1-3c92f37645f7",
                    "name": "availability_zone1",
                    "depends": true,
                    "connections": [
                      {
                        "id": "78a71215-9fb7-439e-b7d4-404a258fb50a",
                        "interface_id": "5246d855-2b0d-40e7-bfc1-3c92f37645f7",
                        "remote_interface_id": "9067b337-c820-4315-8099-413cddc3d083",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::AvailabilityZone"
                  }
                ],
                "vpc_id": "16ef4f23-56e2-4f45-a2e3-f20e907bbfcf",
                "primary_key": "",
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
                "depends": null,
                "parent_id": "7bc345f2-d337-46ff-843f-55d54d3f91fc",
                "properties": [
                  {
                    "name": "size",
                    "value": "60",
                    "form_options": {
                      "type": "range",
                      "min": "1",
                      "max": "16384",
                      "step": "1"
                    },
                    "title": "Size"
                  },
                  {
                    "name": "volume_type",
                    "value": "General Purpose (SSD)",
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
                    "value": "/dev/sda1",
                    "form_options": {
                      "type": "text"
                    },
                    "title": "Device Path"
                  },
                  {
                    "name": "delete_on_termination",
                    "value": false,
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
                    "value": true,
                    "form_options": {
                      "type": "hidden",
                      "readonly": "readonly"
                    },
                    "title": "Configured With AMI"
                  },
                  {
                    "name": "create_from_snapshot",
                    "value": false,
                    "form_options": {
                      "environment_with_snapshots_exists": false,
                      "unallocated_snapshots_exists": false,
                      "type": "checkbox"
                    },
                    "title": "Create From Snapshot"
                  },
                  {
                    "name": "snapshot_id",
                    "value": "",
                    "form_options": {
                      "type": "select",
                      "options": []
                    },
                    "title": "Snapshot ID"
                  },
                  {
                    "name": "snapshot_provider_id",
                    "value": "",
                    "form_options": {
                      "type": "hidden",
                      "readonly": "readonly"
                    },
                    "title": "Snapshot Provider ID"
                  },
                  {
                    "name": "snapshot_environment_id",
                    "value": "",
                    "form_options": {
                      "type": "select",
                      "options": []
                    },
                    "title": "Snapshot Environment ID"
                  }
                ]
              },
              "properties": [
                {
                  "name": "size",
                  "value": "60",
                  "form_options": {
                    "type": "range",
                    "min": "1",
                    "max": "16384",
                    "step": "1"
                  },
                  "title": "Size"
                },
                {
                  "name": "volume_type",
                  "value": "General Purpose (SSD)",
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
                  "value": "/dev/sda1",
                  "form_options": {
                    "type": "text"
                  },
                  "title": "Device Path"
                },
                {
                  "name": "delete_on_termination",
                  "value": false,
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
                  "value": true,
                  "form_options": {
                    "type": "hidden",
                    "readonly": "readonly"
                  },
                  "title": "Configured With AMI"
                },
                {
                  "name": "create_from_snapshot",
                  "value": false,
                  "form_options": {
                    "environment_with_snapshots_exists": false,
                    "unallocated_snapshots_exists": false,
                    "type": "checkbox"
                  },
                  "title": "Create From Snapshot"
                },
                {
                  "name": "snapshot_id",
                  "value": "",
                  "form_options": {
                    "type": "select",
                    "options": []
                  },
                  "title": "Snapshot ID"
                },
                {
                  "name": "snapshot_provider_id",
                  "value": "",
                  "form_options": {
                    "type": "hidden",
                    "readonly": "readonly"
                  },
                  "title": "Snapshot Provider ID"
                },
                {
                  "name": "snapshot_environment_id",
                  "value": "",
                  "form_options": {
                    "type": "select",
                    "options": []
                  },
                  "title": "Snapshot Environment ID"
                }
              ],
              "interfaces": [
                {
                  "id": "00b8306b-5256-4a02-8c86-75a54f959ab5",
                  "name": "volume",
                  "interface_type": "Protocols::Disk",
                  "depends": false,
                  "connections": []
                },
                {
                  "id": "5246d855-2b0d-40e7-bfc1-3c92f37645f7",
                  "name": "availability_zone1",
                  "interface_type": "Protocols::AvailabilityZone",
                  "depends": true,
                  "connections": [
                    {
                      "id": "78a71215-9fb7-439e-b7d4-404a258fb50a",
                      "interface_id": "5246d855-2b0d-40e7-bfc1-3c92f37645f7",
                      "remote_interface_id": "9067b337-c820-4315-8099-413cddc3d083",
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
              "id": "13473fa7-0662-4921-9fab-dc847f48f128",
              "name": "VOL-$$serviceowner-$$function##-V20-",
              "type": "Services::Compute::Server::Volume::AWS",
              "geometry": {
                "x": "10",
                "y": "21",
                "width": "25",
                "height": "25"
              },
              "internal": true,
              "additional_properties": {
                "id": "13473fa7-0662-4921-9fab-dc847f48f128",
                "numbered": false,
                "edge": false,
                "service_type": "Volume",
                "generic_type": "Services::Compute::Server::Volume",
                "type": "Services::Compute::Server::Volume::AWS",
                "name": "VOL-$$serviceowner-$$function##-V20-",
                "internal": true,
                "drawable": false,
                "draggable": true,
                "interfaces": [
                  {
                    "id": "f90e3fbc-4df8-4b1f-a541-bb0a92d3a3ba",
                    "name": "volume",
                    "depends": false,
                    "connections": null,
                    "interface_type": "Protocols::Disk"
                  },
                  {
                    "id": "09f5af15-2cd0-428e-b945-fb8c14f4e643",
                    "name": "availability_zone1",
                    "depends": true,
                    "connections": [
                      {
                        "id": "d6f9c560-5740-4b19-a94d-964d91bce2f8",
                        "interface_id": "09f5af15-2cd0-428e-b945-fb8c14f4e643",
                        "remote_interface_id": "f1629909-7efa-432b-9844-65af8bfc84c2",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::AvailabilityZone"
                  }
                ],
                "vpc_id": "16ef4f23-56e2-4f45-a2e3-f20e907bbfcf",
                "primary_key": "",
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
                "depends": null,
                "parent_id": "ba078cce-e361-4c79-ae32-fa4c4bea2537",
                "properties": [
                  {
                    "name": "size",
                    "value": "30",
                    "form_options": {
                      "type": "range",
                      "min": "1",
                      "max": "16384",
                      "step": "1"
                    },
                    "title": "Size"
                  },
                  {
                    "name": "volume_type",
                    "value": "General Purpose (SSD)",
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
                    "value": "/dev/sda1",
                    "form_options": {
                      "type": "text"
                    },
                    "title": "Device Path"
                  },
                  {
                    "name": "delete_on_termination",
                    "value": false,
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
                    "value": true,
                    "form_options": {
                      "type": "hidden",
                      "readonly": "readonly"
                    },
                    "title": "Configured With AMI"
                  },
                  {
                    "name": "create_from_snapshot",
                    "value": false,
                    "form_options": {
                      "environment_with_snapshots_exists": false,
                      "unallocated_snapshots_exists": false,
                      "type": "checkbox"
                    },
                    "title": "Create From Snapshot"
                  },
                  {
                    "name": "snapshot_id",
                    "value": "",
                    "form_options": {
                      "type": "select",
                      "options": []
                    },
                    "title": "Snapshot ID"
                  },
                  {
                    "name": "snapshot_provider_id",
                    "value": "",
                    "form_options": {
                      "type": "hidden",
                      "readonly": "readonly"
                    },
                    "title": "Snapshot Provider ID"
                  },
                  {
                    "name": "snapshot_environment_id",
                    "value": "",
                    "form_options": {
                      "type": "select",
                      "options": []
                    },
                    "title": "Snapshot Environment ID"
                  }
                ]
              },
              "properties": [
                {
                  "name": "size",
                  "value": "30",
                  "form_options": {
                    "type": "range",
                    "min": "1",
                    "max": "16384",
                    "step": "1"
                  },
                  "title": "Size"
                },
                {
                  "name": "volume_type",
                  "value": "General Purpose (SSD)",
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
                  "value": "/dev/sda1",
                  "form_options": {
                    "type": "text"
                  },
                  "title": "Device Path"
                },
                {
                  "name": "delete_on_termination",
                  "value": false,
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
                  "value": true,
                  "form_options": {
                    "type": "hidden",
                    "readonly": "readonly"
                  },
                  "title": "Configured With AMI"
                },
                {
                  "name": "create_from_snapshot",
                  "value": false,
                  "form_options": {
                    "environment_with_snapshots_exists": false,
                    "unallocated_snapshots_exists": false,
                    "type": "checkbox"
                  },
                  "title": "Create From Snapshot"
                },
                {
                  "name": "snapshot_id",
                  "value": "",
                  "form_options": {
                    "type": "select",
                    "options": []
                  },
                  "title": "Snapshot ID"
                },
                {
                  "name": "snapshot_provider_id",
                  "value": "",
                  "form_options": {
                    "type": "hidden",
                    "readonly": "readonly"
                  },
                  "title": "Snapshot Provider ID"
                },
                {
                  "name": "snapshot_environment_id",
                  "value": "",
                  "form_options": {
                    "type": "select",
                    "options": []
                  },
                  "title": "Snapshot Environment ID"
                }
              ],
              "interfaces": [
                {
                  "id": "f90e3fbc-4df8-4b1f-a541-bb0a92d3a3ba",
                  "name": "volume",
                  "interface_type": "Protocols::Disk",
                  "depends": false,
                  "connections": []
                },
                {
                  "id": "09f5af15-2cd0-428e-b945-fb8c14f4e643",
                  "name": "availability_zone1",
                  "interface_type": "Protocols::AvailabilityZone",
                  "depends": true,
                  "connections": [
                    {
                      "id": "d6f9c560-5740-4b19-a94d-964d91bce2f8",
                      "interface_id": "09f5af15-2cd0-428e-b945-fb8c14f4e643",
                      "remote_interface_id": "f1629909-7efa-432b-9844-65af8bfc84c2",
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
              "id": "3af37d91-94da-43d0-8992-e0c9f1f21b86",
              "name": "VOL-$$serviceowner-$$function##-V20-",
              "type": "Services::Compute::Server::Volume::AWS",
              "geometry": {
                "x": "10",
                "y": "21",
                "width": "25",
                "height": "25"
              },
              "internal": true,
              "additional_properties": {
                "id": "3af37d91-94da-43d0-8992-e0c9f1f21b86",
                "numbered": false,
                "edge": false,
                "service_type": "Volume",
                "generic_type": "Services::Compute::Server::Volume",
                "type": "Services::Compute::Server::Volume::AWS",
                "name": "VOL-$$serviceowner-$$function##-V20-",
                "internal": true,
                "drawable": false,
                "draggable": true,
                "interfaces": [
                  {
                    "id": "e621f9f3-a4d6-418f-86ae-9e142e48a5e9",
                    "name": "volume",
                    "depends": false,
                    "connections": null,
                    "interface_type": "Protocols::Disk"
                  },
                  {
                    "id": "a965302d-dff0-44f9-bacf-a5a0626b7c94",
                    "name": "availability_zone1",
                    "depends": true,
                    "connections": [
                      {
                        "id": "ded35f47-0234-4186-87ae-ff4a9c63af1e",
                        "interface_id": "a965302d-dff0-44f9-bacf-a5a0626b7c94",
                        "remote_interface_id": "9067b337-c820-4315-8099-413cddc3d083",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::AvailabilityZone"
                  }
                ],
                "vpc_id": "16ef4f23-56e2-4f45-a2e3-f20e907bbfcf",
                "primary_key": "",
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
                "depends": null,
                "parent_id": "280cce63-8227-41f6-a7b0-44af2ceb741d",
                "properties": [
                  {
                    "name": "size",
                    "value": "1",
                    "form_options": {
                      "type": "range",
                      "min": "1",
                      "max": "16384",
                      "step": "1"
                    },
                    "title": "Size"
                  },
                  {
                    "name": "volume_type",
                    "value": "General Purpose (SSD)",
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
                    "value": "/dev/sdg",
                    "form_options": {
                      "type": "text"
                    },
                    "title": "Device Path"
                  },
                  {
                    "name": "delete_on_termination",
                    "value": false,
                    "form_options": {
                      "type": "checkbox"
                    },
                    "title": "Delete On Termination"
                  },
                  {
                    "name": "root_device",
                    "value": false,
                    "form_options": {
                      "type": "hidden",
                      "readonly": "readonly"
                    },
                    "title": "Root Device"
                  },
                  {
                    "name": "configured_with_ami",
                    "value": true,
                    "form_options": {
                      "type": "hidden",
                      "readonly": "readonly"
                    },
                    "title": "Configured With AMI"
                  },
                  {
                    "name": "encrypted",
                    "value": false,
                    "form_options": {
                      "type": "checkbox",
                      "required": false
                    },
                    "title": "Encrypted"
                  },
                  {
                    "name": "create_from_snapshot",
                    "value": false,
                    "form_options": {
                      "environment_with_snapshots_exists": false,
                      "unallocated_snapshots_exists": false,
                      "type": "checkbox"
                    },
                    "title": "Create From Snapshot"
                  },
                  {
                    "name": "snapshot_id",
                    "value": "",
                    "form_options": {
                      "type": "select",
                      "options": []
                    },
                    "title": "Snapshot ID"
                  },
                  {
                    "name": "snapshot_provider_id",
                    "value": "",
                    "form_options": {
                      "type": "hidden",
                      "readonly": "readonly"
                    },
                    "title": "Snapshot Provider ID"
                  },
                  {
                    "name": "snapshot_environment_id",
                    "value": "",
                    "form_options": {
                      "type": "select",
                      "options": []
                    },
                    "title": "Snapshot Environment ID"
                  }
                ]
              },
              "properties": [
                {
                  "name": "size",
                  "value": "1",
                  "form_options": {
                    "type": "range",
                    "min": "1",
                    "max": "16384",
                    "step": "1"
                  },
                  "title": "Size"
                },
                {
                  "name": "volume_type",
                  "value": "General Purpose (SSD)",
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
                  "value": "/dev/sdg",
                  "form_options": {
                    "type": "text"
                  },
                  "title": "Device Path"
                },
                {
                  "name": "delete_on_termination",
                  "value": false,
                  "form_options": {
                    "type": "checkbox"
                  },
                  "title": "Delete On Termination"
                },
                {
                  "name": "root_device",
                  "value": false,
                  "form_options": {
                    "type": "hidden",
                    "readonly": "readonly"
                  },
                  "title": "Root Device"
                },
                {
                  "name": "configured_with_ami",
                  "value": true,
                  "form_options": {
                    "type": "hidden",
                    "readonly": "readonly"
                  },
                  "title": "Configured With AMI"
                },
                {
                  "name": "encrypted",
                  "value": false,
                  "form_options": {
                    "type": "checkbox",
                    "required": false
                  },
                  "title": "Encrypted"
                },
                {
                  "name": "create_from_snapshot",
                  "value": false,
                  "form_options": {
                    "environment_with_snapshots_exists": false,
                    "unallocated_snapshots_exists": false,
                    "type": "checkbox"
                  },
                  "title": "Create From Snapshot"
                },
                {
                  "name": "snapshot_id",
                  "value": "",
                  "form_options": {
                    "type": "select",
                    "options": []
                  },
                  "title": "Snapshot ID"
                },
                {
                  "name": "snapshot_provider_id",
                  "value": "",
                  "form_options": {
                    "type": "hidden",
                    "readonly": "readonly"
                  },
                  "title": "Snapshot Provider ID"
                },
                {
                  "name": "snapshot_environment_id",
                  "value": "",
                  "form_options": {
                    "type": "select",
                    "options": []
                  },
                  "title": "Snapshot Environment ID"
                }
              ],
              "interfaces": [
                {
                  "id": "e621f9f3-a4d6-418f-86ae-9e142e48a5e9",
                  "name": "volume",
                  "interface_type": "Protocols::Disk",
                  "depends": false,
                  "connections": []
                },
                {
                  "id": "a965302d-dff0-44f9-bacf-a5a0626b7c94",
                  "name": "availability_zone1",
                  "interface_type": "Protocols::AvailabilityZone",
                  "depends": true,
                  "connections": [
                    {
                      "id": "ded35f47-0234-4186-87ae-ff4a9c63af1e",
                      "interface_id": "a965302d-dff0-44f9-bacf-a5a0626b7c94",
                      "remote_interface_id": "9067b337-c820-4315-8099-413cddc3d083",
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
              "id": "9d0f9856-381f-4f74-8b4a-60579cc359c9",
              "name": "VOL-$$serviceowner-$$function##-V20-",
              "type": "Services::Compute::Server::Volume::AWS",
              "geometry": {
                "x": "10",
                "y": "21",
                "width": "25",
                "height": "25"
              },
              "internal": true,
              "additional_properties": {
                "id": "9d0f9856-381f-4f74-8b4a-60579cc359c9",
                "numbered": false,
                "edge": false,
                "service_type": "Volume",
                "generic_type": "Services::Compute::Server::Volume",
                "type": "Services::Compute::Server::Volume::AWS",
                "name": "VOL-$$serviceowner-$$function##-V20-",
                "internal": true,
                "drawable": false,
                "draggable": true,
                "interfaces": [
                  {
                    "id": "28364273-19f1-46ff-9141-e7d365cfb998",
                    "name": "volume",
                    "depends": false,
                    "connections": null,
                    "interface_type": "Protocols::Disk"
                  },
                  {
                    "id": "0a48ed53-33bd-49b3-853c-9b28aa8e953b",
                    "name": "availability_zone1",
                    "depends": true,
                    "connections": [
                      {
                        "id": "0c8126d8-789b-4f93-bd42-0e7585f96a23",
                        "interface_id": "0a48ed53-33bd-49b3-853c-9b28aa8e953b",
                        "remote_interface_id": "9067b337-c820-4315-8099-413cddc3d083",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::AvailabilityZone"
                  }
                ],
                "vpc_id": "16ef4f23-56e2-4f45-a2e3-f20e907bbfcf",
                "primary_key": "",
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
                "depends": null,
                "parent_id": "280cce63-8227-41f6-a7b0-44af2ceb741d",
                "properties": [
                  {
                    "name": "size",
                    "value": "1",
                    "form_options": {
                      "type": "range",
                      "min": "1",
                      "max": "16384",
                      "step": "1"
                    },
                    "title": "Size"
                  },
                  {
                    "name": "volume_type",
                    "value": "General Purpose (SSD)",
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
                    "value": "/dev/sdf",
                    "form_options": {
                      "type": "text"
                    },
                    "title": "Device Path"
                  },
                  {
                    "name": "delete_on_termination",
                    "value": false,
                    "form_options": {
                      "type": "checkbox"
                    },
                    "title": "Delete On Termination"
                  },
                  {
                    "name": "root_device",
                    "value": false,
                    "form_options": {
                      "type": "hidden",
                      "readonly": "readonly"
                    },
                    "title": "Root Device"
                  },
                  {
                    "name": "configured_with_ami",
                    "value": true,
                    "form_options": {
                      "type": "hidden",
                      "readonly": "readonly"
                    },
                    "title": "Configured With AMI"
                  },
                  {
                    "name": "encrypted",
                    "value": false,
                    "form_options": {
                      "type": "checkbox",
                      "required": false
                    },
                    "title": "Encrypted"
                  },
                  {
                    "name": "create_from_snapshot",
                    "value": false,
                    "form_options": {
                      "environment_with_snapshots_exists": false,
                      "unallocated_snapshots_exists": false,
                      "type": "checkbox"
                    },
                    "title": "Create From Snapshot"
                  },
                  {
                    "name": "snapshot_id",
                    "value": "",
                    "form_options": {
                      "type": "select",
                      "options": []
                    },
                    "title": "Snapshot ID"
                  },
                  {
                    "name": "snapshot_provider_id",
                    "value": "",
                    "form_options": {
                      "type": "hidden",
                      "readonly": "readonly"
                    },
                    "title": "Snapshot Provider ID"
                  },
                  {
                    "name": "snapshot_environment_id",
                    "value": "",
                    "form_options": {
                      "type": "select",
                      "options": []
                    },
                    "title": "Snapshot Environment ID"
                  }
                ]
              },
              "properties": [
                {
                  "name": "size",
                  "value": "1",
                  "form_options": {
                    "type": "range",
                    "min": "1",
                    "max": "16384",
                    "step": "1"
                  },
                  "title": "Size"
                },
                {
                  "name": "volume_type",
                  "value": "General Purpose (SSD)",
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
                  "value": "/dev/sdf",
                  "form_options": {
                    "type": "text"
                  },
                  "title": "Device Path"
                },
                {
                  "name": "delete_on_termination",
                  "value": false,
                  "form_options": {
                    "type": "checkbox"
                  },
                  "title": "Delete On Termination"
                },
                {
                  "name": "root_device",
                  "value": false,
                  "form_options": {
                    "type": "hidden",
                    "readonly": "readonly"
                  },
                  "title": "Root Device"
                },
                {
                  "name": "configured_with_ami",
                  "value": true,
                  "form_options": {
                    "type": "hidden",
                    "readonly": "readonly"
                  },
                  "title": "Configured With AMI"
                },
                {
                  "name": "encrypted",
                  "value": false,
                  "form_options": {
                    "type": "checkbox",
                    "required": false
                  },
                  "title": "Encrypted"
                },
                {
                  "name": "create_from_snapshot",
                  "value": false,
                  "form_options": {
                    "environment_with_snapshots_exists": false,
                    "unallocated_snapshots_exists": false,
                    "type": "checkbox"
                  },
                  "title": "Create From Snapshot"
                },
                {
                  "name": "snapshot_id",
                  "value": "",
                  "form_options": {
                    "type": "select",
                    "options": []
                  },
                  "title": "Snapshot ID"
                },
                {
                  "name": "snapshot_provider_id",
                  "value": "",
                  "form_options": {
                    "type": "hidden",
                    "readonly": "readonly"
                  },
                  "title": "Snapshot Provider ID"
                },
                {
                  "name": "snapshot_environment_id",
                  "value": "",
                  "form_options": {
                    "type": "select",
                    "options": []
                  },
                  "title": "Snapshot Environment ID"
                }
              ],
              "interfaces": [
                {
                  "id": "28364273-19f1-46ff-9141-e7d365cfb998",
                  "name": "volume",
                  "interface_type": "Protocols::Disk",
                  "depends": false,
                  "connections": []
                },
                {
                  "id": "0a48ed53-33bd-49b3-853c-9b28aa8e953b",
                  "name": "availability_zone1",
                  "interface_type": "Protocols::AvailabilityZone",
                  "depends": true,
                  "connections": [
                    {
                      "id": "0c8126d8-789b-4f93-bd42-0e7585f96a23",
                      "interface_id": "0a48ed53-33bd-49b3-853c-9b28aa8e953b",
                      "remote_interface_id": "9067b337-c820-4315-8099-413cddc3d083",
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
              "id": "53bb7e19-55ac-4d64-bbce-934f358d788f",
              "name": "VOL-$$serviceowner-$$function##-V20-",
              "type": "Services::Compute::Server::Volume::AWS",
              "geometry": {
                "x": "10",
                "y": "21",
                "width": "25",
                "height": "25"
              },
              "internal": true,
              "additional_properties": {
                "id": "53bb7e19-55ac-4d64-bbce-934f358d788f",
                "numbered": false,
                "edge": false,
                "service_type": "Volume",
                "generic_type": "Services::Compute::Server::Volume",
                "type": "Services::Compute::Server::Volume::AWS",
                "name": "VOL-$$serviceowner-$$function##-V20-",
                "internal": true,
                "drawable": false,
                "draggable": true,
                "interfaces": [
                  {
                    "id": "f9075414-1e7b-492b-b862-c2de535e4de5",
                    "name": "volume",
                    "depends": false,
                    "connections": null,
                    "interface_type": "Protocols::Disk"
                  },
                  {
                    "id": "9ae7b0d3-73be-4770-a21d-1ed9cf85cdbc",
                    "name": "availability_zone1",
                    "depends": true,
                    "connections": [
                      {
                        "id": "fe98faea-2146-4ef8-82a3-d72a37b86793",
                        "interface_id": "9ae7b0d3-73be-4770-a21d-1ed9cf85cdbc",
                        "remote_interface_id": "9067b337-c820-4315-8099-413cddc3d083",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::AvailabilityZone"
                  }
                ],
                "vpc_id": "16ef4f23-56e2-4f45-a2e3-f20e907bbfcf",
                "primary_key": "",
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
                "depends": null,
                "parent_id": "280cce63-8227-41f6-a7b0-44af2ceb741d",
                "properties": [
                  {
                    "name": "size",
                    "value": "60",
                    "form_options": {
                      "type": "range",
                      "min": "1",
                      "max": "16384",
                      "step": "1"
                    },
                    "title": "Size"
                  },
                  {
                    "name": "volume_type",
                    "value": "General Purpose (SSD)",
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
                    "value": "/dev/sda1",
                    "form_options": {
                      "type": "text"
                    },
                    "title": "Device Path"
                  },
                  {
                    "name": "delete_on_termination",
                    "value": false,
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
                    "value": true,
                    "form_options": {
                      "type": "hidden",
                      "readonly": "readonly"
                    },
                    "title": "Configured With AMI"
                  },
                  {
                    "name": "create_from_snapshot",
                    "value": false,
                    "form_options": {
                      "environment_with_snapshots_exists": false,
                      "unallocated_snapshots_exists": false,
                      "type": "checkbox"
                    },
                    "title": "Create From Snapshot"
                  },
                  {
                    "name": "snapshot_id",
                    "value": "",
                    "form_options": {
                      "type": "select",
                      "options": []
                    },
                    "title": "Snapshot ID"
                  },
                  {
                    "name": "snapshot_provider_id",
                    "value": "",
                    "form_options": {
                      "type": "hidden",
                      "readonly": "readonly"
                    },
                    "title": "Snapshot Provider ID"
                  },
                  {
                    "name": "snapshot_environment_id",
                    "value": "",
                    "form_options": {
                      "type": "select",
                      "options": []
                    },
                    "title": "Snapshot Environment ID"
                  }
                ]
              },
              "properties": [
                {
                  "name": "size",
                  "value": "60",
                  "form_options": {
                    "type": "range",
                    "min": "1",
                    "max": "16384",
                    "step": "1"
                  },
                  "title": "Size"
                },
                {
                  "name": "volume_type",
                  "value": "General Purpose (SSD)",
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
                  "value": "/dev/sda1",
                  "form_options": {
                    "type": "text"
                  },
                  "title": "Device Path"
                },
                {
                  "name": "delete_on_termination",
                  "value": false,
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
                  "value": true,
                  "form_options": {
                    "type": "hidden",
                    "readonly": "readonly"
                  },
                  "title": "Configured With AMI"
                },
                {
                  "name": "create_from_snapshot",
                  "value": false,
                  "form_options": {
                    "environment_with_snapshots_exists": false,
                    "unallocated_snapshots_exists": false,
                    "type": "checkbox"
                  },
                  "title": "Create From Snapshot"
                },
                {
                  "name": "snapshot_id",
                  "value": "",
                  "form_options": {
                    "type": "select",
                    "options": []
                  },
                  "title": "Snapshot ID"
                },
                {
                  "name": "snapshot_provider_id",
                  "value": "",
                  "form_options": {
                    "type": "hidden",
                    "readonly": "readonly"
                  },
                  "title": "Snapshot Provider ID"
                },
                {
                  "name": "snapshot_environment_id",
                  "value": "",
                  "form_options": {
                    "type": "select",
                    "options": []
                  },
                  "title": "Snapshot Environment ID"
                }
              ],
              "interfaces": [
                {
                  "id": "f9075414-1e7b-492b-b862-c2de535e4de5",
                  "name": "volume",
                  "interface_type": "Protocols::Disk",
                  "depends": false,
                  "connections": []
                },
                {
                  "id": "9ae7b0d3-73be-4770-a21d-1ed9cf85cdbc",
                  "name": "availability_zone1",
                  "interface_type": "Protocols::AvailabilityZone",
                  "depends": true,
                  "connections": [
                    {
                      "id": "fe98faea-2146-4ef8-82a3-d72a37b86793",
                      "interface_id": "9ae7b0d3-73be-4770-a21d-1ed9cf85cdbc",
                      "remote_interface_id": "9067b337-c820-4315-8099-413cddc3d083",
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
              "id": "595c0c42-2928-401d-be41-36d50ebdcd3d",
              "name": "VOL-$$serviceowner-$$function##-V20-",
              "type": "Services::Compute::Server::Volume::AWS",
              "geometry": {
                "x": "10",
                "y": "21",
                "width": "25",
                "height": "25"
              },
              "internal": true,
              "additional_properties": {
                "id": "595c0c42-2928-401d-be41-36d50ebdcd3d",
                "numbered": false,
                "edge": false,
                "service_type": "Volume",
                "generic_type": "Services::Compute::Server::Volume",
                "type": "Services::Compute::Server::Volume::AWS",
                "name": "VOL-$$serviceowner-$$function##-V20-",
                "internal": true,
                "drawable": false,
                "draggable": true,
                "interfaces": [
                  {
                    "id": "efae9795-d005-4b56-bbac-e70387cf3d34",
                    "name": "volume",
                    "depends": false,
                    "connections": null,
                    "interface_type": "Protocols::Disk"
                  },
                  {
                    "id": "e44e9f8c-c699-4350-8f99-594e5ee738bc",
                    "name": "availability_zone1",
                    "depends": true,
                    "connections": [
                      {
                        "id": "63ad604d-1f58-4c62-a65c-a3dc5b1de530",
                        "interface_id": "e44e9f8c-c699-4350-8f99-594e5ee738bc",
                        "remote_interface_id": "9067b337-c820-4315-8099-413cddc3d083",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::AvailabilityZone"
                  }
                ],
                "vpc_id": "16ef4f23-56e2-4f45-a2e3-f20e907bbfcf",
                "primary_key": "",
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
                "depends": null,
                "parent_id": "7bc345f2-d337-46ff-843f-55d54d3f91fc",
                "properties": [
                  {
                    "name": "size",
                    "value": "1",
                    "form_options": {
                      "type": "range",
                      "min": "1",
                      "max": "16384",
                      "step": "1"
                    },
                    "title": "Size"
                  },
                  {
                    "name": "volume_type",
                    "value": "General Purpose (SSD)",
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
                    "value": "/dev/sdg",
                    "form_options": {
                      "type": "text"
                    },
                    "title": "Device Path"
                  },
                  {
                    "name": "delete_on_termination",
                    "value": false,
                    "form_options": {
                      "type": "checkbox"
                    },
                    "title": "Delete On Termination"
                  },
                  {
                    "name": "root_device",
                    "value": false,
                    "form_options": {
                      "type": "hidden",
                      "readonly": "readonly"
                    },
                    "title": "Root Device"
                  },
                  {
                    "name": "configured_with_ami",
                    "value": true,
                    "form_options": {
                      "type": "hidden",
                      "readonly": "readonly"
                    },
                    "title": "Configured With AMI"
                  },
                  {
                    "name": "encrypted",
                    "value": false,
                    "form_options": {
                      "type": "checkbox",
                      "required": false
                    },
                    "title": "Encrypted"
                  },
                  {
                    "name": "create_from_snapshot",
                    "value": false,
                    "form_options": {
                      "environment_with_snapshots_exists": false,
                      "unallocated_snapshots_exists": false,
                      "type": "checkbox"
                    },
                    "title": "Create From Snapshot"
                  },
                  {
                    "name": "snapshot_id",
                    "value": "",
                    "form_options": {
                      "type": "select",
                      "options": []
                    },
                    "title": "Snapshot ID"
                  },
                  {
                    "name": "snapshot_provider_id",
                    "value": "",
                    "form_options": {
                      "type": "hidden",
                      "readonly": "readonly"
                    },
                    "title": "Snapshot Provider ID"
                  },
                  {
                    "name": "snapshot_environment_id",
                    "value": "",
                    "form_options": {
                      "type": "select",
                      "options": []
                    },
                    "title": "Snapshot Environment ID"
                  }
                ]
              },
              "properties": [
                {
                  "name": "size",
                  "value": "1",
                  "form_options": {
                    "type": "range",
                    "min": "1",
                    "max": "16384",
                    "step": "1"
                  },
                  "title": "Size"
                },
                {
                  "name": "volume_type",
                  "value": "General Purpose (SSD)",
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
                  "value": "/dev/sdg",
                  "form_options": {
                    "type": "text"
                  },
                  "title": "Device Path"
                },
                {
                  "name": "delete_on_termination",
                  "value": false,
                  "form_options": {
                    "type": "checkbox"
                  },
                  "title": "Delete On Termination"
                },
                {
                  "name": "root_device",
                  "value": false,
                  "form_options": {
                    "type": "hidden",
                    "readonly": "readonly"
                  },
                  "title": "Root Device"
                },
                {
                  "name": "configured_with_ami",
                  "value": true,
                  "form_options": {
                    "type": "hidden",
                    "readonly": "readonly"
                  },
                  "title": "Configured With AMI"
                },
                {
                  "name": "encrypted",
                  "value": false,
                  "form_options": {
                    "type": "checkbox",
                    "required": false
                  },
                  "title": "Encrypted"
                },
                {
                  "name": "create_from_snapshot",
                  "value": false,
                  "form_options": {
                    "environment_with_snapshots_exists": false,
                    "unallocated_snapshots_exists": false,
                    "type": "checkbox"
                  },
                  "title": "Create From Snapshot"
                },
                {
                  "name": "snapshot_id",
                  "value": "",
                  "form_options": {
                    "type": "select",
                    "options": []
                  },
                  "title": "Snapshot ID"
                },
                {
                  "name": "snapshot_provider_id",
                  "value": "",
                  "form_options": {
                    "type": "hidden",
                    "readonly": "readonly"
                  },
                  "title": "Snapshot Provider ID"
                },
                {
                  "name": "snapshot_environment_id",
                  "value": "",
                  "form_options": {
                    "type": "select",
                    "options": []
                  },
                  "title": "Snapshot Environment ID"
                }
              ],
              "interfaces": [
                {
                  "id": "efae9795-d005-4b56-bbac-e70387cf3d34",
                  "name": "volume",
                  "interface_type": "Protocols::Disk",
                  "depends": false,
                  "connections": []
                },
                {
                  "id": "e44e9f8c-c699-4350-8f99-594e5ee738bc",
                  "name": "availability_zone1",
                  "interface_type": "Protocols::AvailabilityZone",
                  "depends": true,
                  "connections": [
                    {
                      "id": "63ad604d-1f58-4c62-a65c-a3dc5b1de530",
                      "interface_id": "e44e9f8c-c699-4350-8f99-594e5ee738bc",
                      "remote_interface_id": "9067b337-c820-4315-8099-413cddc3d083",
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
              "id": "4e7d8ade-bc9e-43e8-884a-1b4440a65690",
              "name": "eni1",
              "type": "Services::Network::NetworkInterface::AWS",
              "geometry": {
                "x": "10",
                "y": "23",
                "width": "0",
                "height": "0"
              },
              "internal": true,
              "expose": true,
              "additional_properties": {
                "id": "4e7d8ade-bc9e-43e8-884a-1b4440a65690",
                "numbered": false,
                "edge": false,
                "service_type": "NetworkInterface",
                "generic_type": "Services::Network::NetworkInterface",
                "type": "Services::Network::NetworkInterface::AWS",
                "name": "eni1",
                "internal": true,
                "drawable": true,
                "draggable": true,
                "interfaces": [
                  {
                    "id": "b8fefe53-be4c-449b-a9e7-ef3224e5e6c5",
                    "name": "win01",
                    "depends": true,
                    "connections": [
                      {
                        "id": "06351960-91f4-4f93-8149-ed99cf305072",
                        "interface_id": "b8fefe53-be4c-449b-a9e7-ef3224e5e6c5",
                        "remote_interface_id": "39ca6d55-cc48-402c-a843-d225af4dee1e",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::Server"
                  },
                  {
                    "id": "1bcf675c-ed94-4d33-868e-28c0bf8aef43",
                    "name": "default",
                    "depends": true,
                    "connections": [
                      {
                        "id": "33b256b9-9965-4db6-8eac-6c23e4fdf053",
                        "interface_id": "1bcf675c-ed94-4d33-868e-28c0bf8aef43",
                        "remote_interface_id": "94c76455-6f91-4128-8db1-d90b323cf891",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::SecurityGroup"
                  },
                  {
                    "id": "1fabd948-bffd-432d-a0d6-9a864bcd49c4",
                    "name": "eni",
                    "depends": false,
                    "connections": null,
                    "interface_type": "Protocols::NetworkInterface"
                  },
                  {
                    "id": "7e01cbac-c3b9-4505-8026-dba7cdff34c1",
                    "name": "SN-V20-$$servername-##",
                    "depends": true,
                    "connections": [
                      {
                        "id": "4231cfc2-eaf7-4c6a-a5f2-1134075bcf33",
                        "interface_id": "7e01cbac-c3b9-4505-8026-dba7cdff34c1",
                        "remote_interface_id": "b2c454b8-ea36-48d2-9b11-140cc3b1a4a4",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::Subnet"
                  },
                  {
                    "id": "ed67ea15-3994-45a6-9d96-d068ba61e57d",
                    "name": "prat",
                    "depends": true,
                    "connections": [
                      {
                        "id": "b2cbfd18-aca8-42dc-9983-35f115f3d331",
                        "interface_id": "ed67ea15-3994-45a6-9d96-d068ba61e57d",
                        "remote_interface_id": "cbb835cb-991c-4b69-a081-195c597025bf",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::Vpc"
                  }
                ],
                "vpc_id": "16ef4f23-56e2-4f45-a2e3-f20e907bbfcf",
                "primary_key": "",
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
                ],
                "expose": true,
                "parent_id": "0af16444-1472-4db3-9a53-8c4707e4f2b6",
                "properties": [
                  {
                    "name": "private_ips",
                    "value": [
                      {
                        "primary": "true",
                        "privateIpAddress": "",
                        "elasticIp": "",
                        "hasElasticIP": false
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
                    "value": "",
                    "form_options": {
                      "type": "text",
                      "required": true
                    },
                    "title": "Description"
                  },
                  {
                    "name": "mac_address",
                    "value": "",
                    "form_options": {
                      "type": "hidden",
                      "readonly": "readonly"
                    },
                    "title": "Mac Address"
                  },
                  {
                    "name": "source_dest_check",
                    "form_options": {
                      "type": "checkbox",
                      "required": false
                    },
                    "title": "Source Destination Check"
                  }
                ]
              },
              "properties": [
                {
                  "name": "private_ips",
                  "value": [
                    {
                      "primary": "true",
                      "privateIpAddress": "",
                      "elasticIp": "",
                      "hasElasticIP": false
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
                  "value": "",
                  "form_options": {
                    "type": "text",
                    "required": true
                  },
                  "title": "Description"
                },
                {
                  "name": "mac_address",
                  "value": "",
                  "form_options": {
                    "type": "hidden",
                    "readonly": "readonly"
                  },
                  "title": "Mac Address"
                },
                {
                  "name": "source_dest_check",
                  "form_options": {
                    "type": "checkbox",
                    "required": false
                  },
                  "title": "Source Destination Check"
                }
              ],
              "interfaces": [
                {
                  "id": "b8fefe53-be4c-449b-a9e7-ef3224e5e6c5",
                  "name": "win01",
                  "interface_type": "Protocols::Server",
                  "depends": true,
                  "connections": [
                    {
                      "id": "06351960-91f4-4f93-8149-ed99cf305072",
                      "interface_id": "b8fefe53-be4c-449b-a9e7-ef3224e5e6c5",
                      "remote_interface_id": "39ca6d55-cc48-402c-a843-d225af4dee1e",
                      "internal": false
                    }
                  ]
                },
                {
                  "id": "1bcf675c-ed94-4d33-868e-28c0bf8aef43",
                  "name": "default",
                  "interface_type": "Protocols::SecurityGroup",
                  "depends": true,
                  "connections": [
                    {
                      "id": "33b256b9-9965-4db6-8eac-6c23e4fdf053",
                      "interface_id": "1bcf675c-ed94-4d33-868e-28c0bf8aef43",
                      "remote_interface_id": "94c76455-6f91-4128-8db1-d90b323cf891",
                      "internal": false
                    }
                  ]
                },
                {
                  "id": "1fabd948-bffd-432d-a0d6-9a864bcd49c4",
                  "name": "eni",
                  "interface_type": "Protocols::NetworkInterface",
                  "depends": false,
                  "connections": []
                },
                {
                  "id": "7e01cbac-c3b9-4505-8026-dba7cdff34c1",
                  "name": "SN-V20-$$servername-##",
                  "interface_type": "Protocols::Subnet",
                  "depends": true,
                  "connections": [
                    {
                      "id": "4231cfc2-eaf7-4c6a-a5f2-1134075bcf33",
                      "interface_id": "7e01cbac-c3b9-4505-8026-dba7cdff34c1",
                      "remote_interface_id": "b2c454b8-ea36-48d2-9b11-140cc3b1a4a4",
                      "internal": false
                    }
                  ]
                },
                {
                  "id": "ed67ea15-3994-45a6-9d96-d068ba61e57d",
                  "name": "prat",
                  "interface_type": "Protocols::Vpc",
                  "depends": true,
                  "connections": [
                    {
                      "id": "b2cbfd18-aca8-42dc-9983-35f115f3d331",
                      "interface_id": "ed67ea15-3994-45a6-9d96-d068ba61e57d",
                      "remote_interface_id": "cbb835cb-991c-4b69-a081-195c597025bf",
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
              "id": "c3110a87-60c4-46a8-be3c-83e62dab3b6e",
              "name": "eni1",
              "type": "Services::Network::NetworkInterface::AWS",
              "geometry": {
                "x": "10",
                "y": "23",
                "width": "0",
                "height": "0"
              },
              "internal": true,
              "expose": true,
              "additional_properties": {
                "id": "c3110a87-60c4-46a8-be3c-83e62dab3b6e",
                "numbered": false,
                "edge": false,
                "service_type": "NetworkInterface",
                "generic_type": "Services::Network::NetworkInterface",
                "type": "Services::Network::NetworkInterface::AWS",
                "name": "eni1",
                "internal": true,
                "drawable": true,
                "draggable": true,
                "interfaces": [
                  {
                    "id": "6317b3d6-0cc4-45be-9c30-08a6261baf50",
                    "name": "c2",
                    "depends": true,
                    "connections": [
                      {
                        "id": "7893b446-d32f-4466-8515-856b7cc46221",
                        "interface_id": "6317b3d6-0cc4-45be-9c30-08a6261baf50",
                        "remote_interface_id": "71dee424-a9fc-45af-91b6-99b6c0d5590a",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::Server"
                  },
                  {
                    "id": "2695e7e2-65d6-48d0-a161-ce0e0b67f5b2",
                    "name": "default",
                    "depends": true,
                    "connections": [
                      {
                        "id": "0fb82f74-44a4-426b-8f78-37007cdc5359",
                        "interface_id": "2695e7e2-65d6-48d0-a161-ce0e0b67f5b2",
                        "remote_interface_id": "94c76455-6f91-4128-8db1-d90b323cf891",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::SecurityGroup"
                  },
                  {
                    "id": "f5d45221-b87f-483b-9099-43180c1c5260",
                    "name": "eni",
                    "depends": false,
                    "connections": null,
                    "interface_type": "Protocols::NetworkInterface"
                  },
                  {
                    "id": "ed6bf0da-cea2-414b-b11d-7fb9b1c6020d",
                    "name": "SN-V20-$$servername-##",
                    "depends": true,
                    "connections": [
                      {
                        "id": "78644d58-fbd7-46b9-b7df-5ae2a2150a44",
                        "interface_id": "ed6bf0da-cea2-414b-b11d-7fb9b1c6020d",
                        "remote_interface_id": "06b21844-962a-48a0-b1d5-4eac6cac5055",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::Subnet"
                  },
                  {
                    "id": "a4864f4a-ea80-4d79-9343-3ed994d8511b",
                    "name": "prat",
                    "depends": true,
                    "connections": [
                      {
                        "id": "9d47651a-07b7-469a-84ff-5b536ce1f5ab",
                        "interface_id": "a4864f4a-ea80-4d79-9343-3ed994d8511b",
                        "remote_interface_id": "cbb835cb-991c-4b69-a081-195c597025bf",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::Vpc"
                  }
                ],
                "vpc_id": "16ef4f23-56e2-4f45-a2e3-f20e907bbfcf",
                "primary_key": "",
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
                ],
                "expose": true,
                "parent_id": "c6fe71d4-e3f1-4de7-ad85-b54a2616a033",
                "properties": [
                  {
                    "name": "private_ips",
                    "value": [
                      {
                        "primary": "true",
                        "privateIpAddress": "",
                        "elasticIp": "",
                        "hasElasticIP": false
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
                    "value": "",
                    "form_options": {
                      "type": "text",
                      "required": true
                    },
                    "title": "Description"
                  },
                  {
                    "name": "mac_address",
                    "value": "",
                    "form_options": {
                      "type": "hidden",
                      "readonly": "readonly"
                    },
                    "title": "Mac Address"
                  },
                  {
                    "name": "source_dest_check",
                    "form_options": {
                      "type": "checkbox",
                      "required": false
                    },
                    "title": "Source Destination Check"
                  }
                ]
              },
              "properties": [
                {
                  "name": "private_ips",
                  "value": [
                    {
                      "primary": "true",
                      "privateIpAddress": "",
                      "elasticIp": "",
                      "hasElasticIP": false
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
                  "value": "",
                  "form_options": {
                    "type": "text",
                    "required": true
                  },
                  "title": "Description"
                },
                {
                  "name": "mac_address",
                  "value": "",
                  "form_options": {
                    "type": "hidden",
                    "readonly": "readonly"
                  },
                  "title": "Mac Address"
                },
                {
                  "name": "source_dest_check",
                  "form_options": {
                    "type": "checkbox",
                    "required": false
                  },
                  "title": "Source Destination Check"
                }
              ],
              "interfaces": [
                {
                  "id": "6317b3d6-0cc4-45be-9c30-08a6261baf50",
                  "name": "c2",
                  "interface_type": "Protocols::Server",
                  "depends": true,
                  "connections": [
                    {
                      "id": "7893b446-d32f-4466-8515-856b7cc46221",
                      "interface_id": "6317b3d6-0cc4-45be-9c30-08a6261baf50",
                      "remote_interface_id": "71dee424-a9fc-45af-91b6-99b6c0d5590a",
                      "internal": false
                    }
                  ]
                },
                {
                  "id": "2695e7e2-65d6-48d0-a161-ce0e0b67f5b2",
                  "name": "default",
                  "interface_type": "Protocols::SecurityGroup",
                  "depends": true,
                  "connections": [
                    {
                      "id": "0fb82f74-44a4-426b-8f78-37007cdc5359",
                      "interface_id": "2695e7e2-65d6-48d0-a161-ce0e0b67f5b2",
                      "remote_interface_id": "94c76455-6f91-4128-8db1-d90b323cf891",
                      "internal": false
                    }
                  ]
                },
                {
                  "id": "f5d45221-b87f-483b-9099-43180c1c5260",
                  "name": "eni",
                  "interface_type": "Protocols::NetworkInterface",
                  "depends": false,
                  "connections": []
                },
                {
                  "id": "ed6bf0da-cea2-414b-b11d-7fb9b1c6020d",
                  "name": "SN-V20-$$servername-##",
                  "interface_type": "Protocols::Subnet",
                  "depends": true,
                  "connections": [
                    {
                      "id": "78644d58-fbd7-46b9-b7df-5ae2a2150a44",
                      "interface_id": "ed6bf0da-cea2-414b-b11d-7fb9b1c6020d",
                      "remote_interface_id": "06b21844-962a-48a0-b1d5-4eac6cac5055",
                      "internal": false
                    }
                  ]
                },
                {
                  "id": "a4864f4a-ea80-4d79-9343-3ed994d8511b",
                  "name": "prat",
                  "interface_type": "Protocols::Vpc",
                  "depends": true,
                  "connections": [
                    {
                      "id": "9d47651a-07b7-469a-84ff-5b536ce1f5ab",
                      "interface_id": "a4864f4a-ea80-4d79-9343-3ed994d8511b",
                      "remote_interface_id": "cbb835cb-991c-4b69-a081-195c597025bf",
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
              "id": "603be4da-8e97-4694-bbd5-6215a853db5d",
              "name": "eni1",
              "type": "Services::Network::NetworkInterface::AWS",
              "geometry": {
                "x": "10",
                "y": "23",
                "width": "0",
                "height": "0"
              },
              "internal": true,
              "expose": true,
              "additional_properties": {
                "id": "603be4da-8e97-4694-bbd5-6215a853db5d",
                "numbered": false,
                "edge": false,
                "service_type": "NetworkInterface",
                "generic_type": "Services::Network::NetworkInterface",
                "type": "Services::Network::NetworkInterface::AWS",
                "name": "eni1",
                "internal": true,
                "drawable": true,
                "draggable": true,
                "interfaces": [
                  {
                    "id": "9f5810ae-c009-459e-ab1f-5d79c9220890",
                    "name": "c2",
                    "depends": true,
                    "connections": [
                      {
                        "id": "fecddf82-9edf-4676-b461-e5f608e6359c",
                        "interface_id": "9f5810ae-c009-459e-ab1f-5d79c9220890",
                        "remote_interface_id": "dea87cd3-0607-4f26-8039-6ac2f292a31f",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::Server"
                  },
                  {
                    "id": "7b3e81a4-b513-44a2-8362-1afc33745b24",
                    "name": "default",
                    "depends": true,
                    "connections": [
                      {
                        "id": "c2e4c54c-5489-4ca5-b272-40bcca3b2fb9",
                        "interface_id": "7b3e81a4-b513-44a2-8362-1afc33745b24",
                        "remote_interface_id": "94c76455-6f91-4128-8db1-d90b323cf891",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::SecurityGroup"
                  },
                  {
                    "id": "cc17dc3a-b530-4426-942a-56a103899ddb",
                    "name": "eni",
                    "depends": false,
                    "connections": null,
                    "interface_type": "Protocols::NetworkInterface"
                  },
                  {
                    "id": "05982368-f34f-4064-8100-2adc922b6472",
                    "name": "SN-V20-$$servername-##",
                    "depends": true,
                    "connections": [
                      {
                        "id": "88b2a92c-22f5-41d9-a75e-a79d35cfcea4",
                        "interface_id": "05982368-f34f-4064-8100-2adc922b6472",
                        "remote_interface_id": "06b21844-962a-48a0-b1d5-4eac6cac5055",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::Subnet"
                  },
                  {
                    "id": "29dbbd66-c3fb-4ce5-8d7e-426f0e236374",
                    "name": "prat",
                    "depends": true,
                    "connections": [
                      {
                        "id": "8f35c077-bde5-4a84-9f86-adf95bfec453",
                        "interface_id": "29dbbd66-c3fb-4ce5-8d7e-426f0e236374",
                        "remote_interface_id": "cbb835cb-991c-4b69-a081-195c597025bf",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::Vpc"
                  }
                ],
                "vpc_id": "16ef4f23-56e2-4f45-a2e3-f20e907bbfcf",
                "primary_key": "",
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
                ],
                "expose": true,
                "parent_id": "c6fe71d4-e3f1-4de7-ad85-b54a2616a033",
                "properties": [
                  {
                    "name": "private_ips",
                    "value": [
                      {
                        "primary": "true",
                        "privateIpAddress": "",
                        "elasticIp": "",
                        "hasElasticIP": false
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
                    "value": "",
                    "form_options": {
                      "type": "text",
                      "required": true
                    },
                    "title": "Description"
                  },
                  {
                    "name": "mac_address",
                    "value": "",
                    "form_options": {
                      "type": "hidden",
                      "readonly": "readonly"
                    },
                    "title": "Mac Address"
                  },
                  {
                    "name": "source_dest_check",
                    "form_options": {
                      "type": "checkbox",
                      "required": false
                    },
                    "title": "Source Destination Check"
                  }
                ]
              },
              "properties": [
                {
                  "name": "private_ips",
                  "value": [
                    {
                      "primary": "true",
                      "privateIpAddress": "",
                      "elasticIp": "",
                      "hasElasticIP": false
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
                  "value": "",
                  "form_options": {
                    "type": "text",
                    "required": true
                  },
                  "title": "Description"
                },
                {
                  "name": "mac_address",
                  "value": "",
                  "form_options": {
                    "type": "hidden",
                    "readonly": "readonly"
                  },
                  "title": "Mac Address"
                },
                {
                  "name": "source_dest_check",
                  "form_options": {
                    "type": "checkbox",
                    "required": false
                  },
                  "title": "Source Destination Check"
                }
              ],
              "interfaces": [
                {
                  "id": "9f5810ae-c009-459e-ab1f-5d79c9220890",
                  "name": "c2",
                  "interface_type": "Protocols::Server",
                  "depends": true,
                  "connections": [
                    {
                      "id": "fecddf82-9edf-4676-b461-e5f608e6359c",
                      "interface_id": "9f5810ae-c009-459e-ab1f-5d79c9220890",
                      "remote_interface_id": "dea87cd3-0607-4f26-8039-6ac2f292a31f",
                      "internal": false
                    }
                  ]
                },
                {
                  "id": "7b3e81a4-b513-44a2-8362-1afc33745b24",
                  "name": "default",
                  "interface_type": "Protocols::SecurityGroup",
                  "depends": true,
                  "connections": [
                    {
                      "id": "c2e4c54c-5489-4ca5-b272-40bcca3b2fb9",
                      "interface_id": "7b3e81a4-b513-44a2-8362-1afc33745b24",
                      "remote_interface_id": "94c76455-6f91-4128-8db1-d90b323cf891",
                      "internal": false
                    }
                  ]
                },
                {
                  "id": "cc17dc3a-b530-4426-942a-56a103899ddb",
                  "name": "eni",
                  "interface_type": "Protocols::NetworkInterface",
                  "depends": false,
                  "connections": []
                },
                {
                  "id": "05982368-f34f-4064-8100-2adc922b6472",
                  "name": "SN-V20-$$servername-##",
                  "interface_type": "Protocols::Subnet",
                  "depends": true,
                  "connections": [
                    {
                      "id": "88b2a92c-22f5-41d9-a75e-a79d35cfcea4",
                      "interface_id": "05982368-f34f-4064-8100-2adc922b6472",
                      "remote_interface_id": "06b21844-962a-48a0-b1d5-4eac6cac5055",
                      "internal": false
                    }
                  ]
                },
                {
                  "id": "29dbbd66-c3fb-4ce5-8d7e-426f0e236374",
                  "name": "prat",
                  "interface_type": "Protocols::Vpc",
                  "depends": true,
                  "connections": [
                    {
                      "id": "8f35c077-bde5-4a84-9f86-adf95bfec453",
                      "interface_id": "29dbbd66-c3fb-4ce5-8d7e-426f0e236374",
                      "remote_interface_id": "cbb835cb-991c-4b69-a081-195c597025bf",
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
              "id": "8a743851-4366-4125-9409-11084403fdcd",
              "name": "eni1",
              "type": "Services::Network::NetworkInterface::AWS",
              "geometry": {
                "x": "10",
                "y": "23",
                "width": "0",
                "height": "0"
              },
              "internal": true,
              "expose": true,
              "additional_properties": {
                "id": "8a743851-4366-4125-9409-11084403fdcd",
                "numbered": false,
                "edge": false,
                "service_type": "NetworkInterface",
                "generic_type": "Services::Network::NetworkInterface",
                "type": "Services::Network::NetworkInterface::AWS",
                "name": "eni1",
                "internal": true,
                "drawable": true,
                "draggable": true,
                "interfaces": [
                  {
                    "id": "368005c2-e04e-486b-93b7-4233475477f5",
                    "name": "c2",
                    "depends": true,
                    "connections": [
                      {
                        "id": "3fbb712e-3b6f-476f-b429-52ca7867ed70",
                        "interface_id": "368005c2-e04e-486b-93b7-4233475477f5",
                        "remote_interface_id": "44802e0e-7257-4ec7-a52c-cac370e9c364",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::Server"
                  },
                  {
                    "id": "de4cf218-30a4-4718-b2ab-7813d9493fa1",
                    "name": "default",
                    "depends": true,
                    "connections": [
                      {
                        "id": "62996122-4e7b-407d-8a5b-6f145161171d",
                        "interface_id": "de4cf218-30a4-4718-b2ab-7813d9493fa1",
                        "remote_interface_id": "94c76455-6f91-4128-8db1-d90b323cf891",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::SecurityGroup"
                  },
                  {
                    "id": "d4229532-a6af-4128-850a-f19778f54de3",
                    "name": "eni",
                    "depends": false,
                    "connections": null,
                    "interface_type": "Protocols::NetworkInterface"
                  },
                  {
                    "id": "cce3cf8b-e495-4ce0-bb10-65e929b3eaf0",
                    "name": "SN-V20-$$servername-##",
                    "depends": true,
                    "connections": [
                      {
                        "id": "61d3f40f-ef0d-4cd8-8290-771595fafbd1",
                        "interface_id": "cce3cf8b-e495-4ce0-bb10-65e929b3eaf0",
                        "remote_interface_id": "95af5f53-00a9-4a86-9e69-fba5bf0336f7",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::Subnet"
                  },
                  {
                    "id": "a685e8bc-1605-4031-8d37-4538c9df2d43",
                    "name": "prat",
                    "depends": true,
                    "connections": [
                      {
                        "id": "1dcdf555-dc2b-4a24-9808-1f1d86fd1b5b",
                        "interface_id": "a685e8bc-1605-4031-8d37-4538c9df2d43",
                        "remote_interface_id": "cbb835cb-991c-4b69-a081-195c597025bf",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::Vpc"
                  }
                ],
                "vpc_id": "16ef4f23-56e2-4f45-a2e3-f20e907bbfcf",
                "primary_key": "",
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
                ],
                "expose": true,
                "parent_id": "0dbde654-1252-49b0-978d-f0fab5713cc5",
                "properties": [
                  {
                    "name": "private_ips",
                    "value": [
                      {
                        "primary": "true",
                        "privateIpAddress": "",
                        "elasticIp": "",
                        "hasElasticIP": false
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
                    "value": "",
                    "form_options": {
                      "type": "text",
                      "required": true
                    },
                    "title": "Description"
                  },
                  {
                    "name": "mac_address",
                    "value": "",
                    "form_options": {
                      "type": "hidden",
                      "readonly": "readonly"
                    },
                    "title": "Mac Address"
                  },
                  {
                    "name": "source_dest_check",
                    "form_options": {
                      "type": "checkbox",
                      "required": false
                    },
                    "title": "Source Destination Check"
                  }
                ]
              },
              "properties": [
                {
                  "name": "private_ips",
                  "value": [
                    {
                      "primary": "true",
                      "privateIpAddress": "",
                      "elasticIp": "",
                      "hasElasticIP": false
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
                  "value": "",
                  "form_options": {
                    "type": "text",
                    "required": true
                  },
                  "title": "Description"
                },
                {
                  "name": "mac_address",
                  "value": "",
                  "form_options": {
                    "type": "hidden",
                    "readonly": "readonly"
                  },
                  "title": "Mac Address"
                },
                {
                  "name": "source_dest_check",
                  "form_options": {
                    "type": "checkbox",
                    "required": false
                  },
                  "title": "Source Destination Check"
                }
              ],
              "interfaces": [
                {
                  "id": "368005c2-e04e-486b-93b7-4233475477f5",
                  "name": "c2",
                  "interface_type": "Protocols::Server",
                  "depends": true,
                  "connections": [
                    {
                      "id": "3fbb712e-3b6f-476f-b429-52ca7867ed70",
                      "interface_id": "368005c2-e04e-486b-93b7-4233475477f5",
                      "remote_interface_id": "44802e0e-7257-4ec7-a52c-cac370e9c364",
                      "internal": false
                    }
                  ]
                },
                {
                  "id": "de4cf218-30a4-4718-b2ab-7813d9493fa1",
                  "name": "default",
                  "interface_type": "Protocols::SecurityGroup",
                  "depends": true,
                  "connections": [
                    {
                      "id": "62996122-4e7b-407d-8a5b-6f145161171d",
                      "interface_id": "de4cf218-30a4-4718-b2ab-7813d9493fa1",
                      "remote_interface_id": "94c76455-6f91-4128-8db1-d90b323cf891",
                      "internal": false
                    }
                  ]
                },
                {
                  "id": "d4229532-a6af-4128-850a-f19778f54de3",
                  "name": "eni",
                  "interface_type": "Protocols::NetworkInterface",
                  "depends": false,
                  "connections": []
                },
                {
                  "id": "cce3cf8b-e495-4ce0-bb10-65e929b3eaf0",
                  "name": "SN-V20-$$servername-##",
                  "interface_type": "Protocols::Subnet",
                  "depends": true,
                  "connections": [
                    {
                      "id": "61d3f40f-ef0d-4cd8-8290-771595fafbd1",
                      "interface_id": "cce3cf8b-e495-4ce0-bb10-65e929b3eaf0",
                      "remote_interface_id": "95af5f53-00a9-4a86-9e69-fba5bf0336f7",
                      "internal": false
                    }
                  ]
                },
                {
                  "id": "a685e8bc-1605-4031-8d37-4538c9df2d43",
                  "name": "prat",
                  "interface_type": "Protocols::Vpc",
                  "depends": true,
                  "connections": [
                    {
                      "id": "1dcdf555-dc2b-4a24-9808-1f1d86fd1b5b",
                      "interface_id": "a685e8bc-1605-4031-8d37-4538c9df2d43",
                      "remote_interface_id": "cbb835cb-991c-4b69-a081-195c597025bf",
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
              "id": "9ac3fad6-b0c6-45e9-bd4f-36cc253f5b79",
              "name": "ISV-V20-##-",
              "type": "Services::Compute::Server::IscsiVolume::AWS",
              "geometry": {
                "x": "10",
                "y": "18",
                "width": "56",
                "height": "56"
              },
              "internal": true,
              "additional_properties": {
                "id": "9ac3fad6-b0c6-45e9-bd4f-36cc253f5b79",
                "numbered": false,
                "edge": false,
                "service_type": "iscsivolume",
                "generic_type": "Services::Compute::Server::IscsiVolume",
                "type": "Services::Compute::Server::IscsiVolume::AWS",
                "name": "ISV-V20-##-",
                "internal": true,
                "drawable": false,
                "draggable": true,
                "interfaces": [
                  {
                    "id": "ad55d554-a23b-489b-ace7-30b67cda92ef",
                    "name": "ISCSI Volume",
                    "depends": false,
                    "connections": null,
                    "interface_type": "Protocols::IscsiVolume"
                  },
                  {
                    "id": "add3df53-d27c-453f-94a5-6cb434534ee9",
                    "name": "EC2-$$servername-$$function##-V20",
                    "depends": true,
                    "connections": [
                      {
                        "id": "4993b884-70f1-487c-9a44-5ef82883095e",
                        "interface_id": "add3df53-d27c-453f-94a5-6cb434534ee9",
                        "remote_interface_id": "44802e0e-7257-4ec7-a52c-cac370e9c364",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::Server"
                  },
                  {
                    "id": "d725523d-250b-4f87-8e39-84a309f29e89",
                    "name": "prat",
                    "depends": true,
                    "connections": [
                      {
                        "id": "3a4a1f21-1912-4d6f-a863-e5c95f50f200",
                        "interface_id": "d725523d-250b-4f87-8e39-84a309f29e89",
                        "remote_interface_id": "cbb835cb-991c-4b69-a081-195c597025bf",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::Vpc"
                  }
                ],
                "vpc_id": "16ef4f23-56e2-4f45-a2e3-f20e907bbfcf",
                "primary_key": "",
                "provides": [
                  {
                    "name": "iscsivolume",
                    "type": "Protocols::IscsiVolume",
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
                "parent_id": "4e621aa6-6a4d-4676-ae41-2b2bd5bb3647",
                "properties": [
                  {
                    "name": "target_ip",
                    "value": "",
                    "form_options": {
                      "type": "text"
                    },
                    "title": "Target Ip"
                  },
                  {
                    "name": "target",
                    "value": "65465494",
                    "form_options": {
                      "type": "text"
                    },
                    "title": "Target"
                  },
                  {
                    "name": "username",
                    "value": "6546546",
                    "form_options": {
                      "type": "text"
                    },
                    "title": "Username"
                  },
                  {
                    "name": "password",
                    "value": "651465146145646546",
                    "form_options": {
                      "type": "password"
                    },
                    "title": "Password"
                  }
                ]
              },
              "properties": [
                {
                  "name": "target_ip",
                  "value": "",
                  "form_options": {
                    "type": "text"
                  },
                  "title": "Target Ip"
                },
                {
                  "name": "target",
                  "value": "65465494",
                  "form_options": {
                    "type": "text"
                  },
                  "title": "Target"
                },
                {
                  "name": "username",
                  "value": "6546546",
                  "form_options": {
                    "type": "text"
                  },
                  "title": "Username"
                },
                {
                  "name": "password",
                  "value": "651465146145646546",
                  "form_options": {
                    "type": "password"
                  },
                  "title": "Password"
                }
              ],
              "interfaces": [
                {
                  "id": "ad55d554-a23b-489b-ace7-30b67cda92ef",
                  "name": "ISCSI Volume",
                  "interface_type": "Protocols::IscsiVolume",
                  "depends": false,
                  "connections": []
                },
                {
                  "id": "add3df53-d27c-453f-94a5-6cb434534ee9",
                  "name": "EC2-$$servername-$$function##-V20",
                  "interface_type": "Protocols::Server",
                  "depends": true,
                  "connections": [
                    {
                      "id": "4993b884-70f1-487c-9a44-5ef82883095e",
                      "interface_id": "add3df53-d27c-453f-94a5-6cb434534ee9",
                      "remote_interface_id": "44802e0e-7257-4ec7-a52c-cac370e9c364",
                      "internal": false
                    }
                  ]
                },
                {
                  "id": "d725523d-250b-4f87-8e39-84a309f29e89",
                  "name": "prat",
                  "interface_type": "Protocols::Vpc",
                  "depends": true,
                  "connections": [
                    {
                      "id": "3a4a1f21-1912-4d6f-a863-e5c95f50f200",
                      "interface_id": "d725523d-250b-4f87-8e39-84a309f29e89",
                      "remote_interface_id": "cbb835cb-991c-4b69-a081-195c597025bf",
                      "internal": false
                    }
                  ]
                }
              ],
              "provides": [
                {
                  "name": "iscsivolume",
                  "type": "Protocols::IscsiVolume",
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
              "id": "d0361002-8f43-4196-be54-e22e2a6f7e49",
              "name": "ELBV20MAZ##",
              "type": "Services::Network::LoadBalancer::AWS",
              "geometry": {
                "x": "430",
                "y": "790",
                "width": "56",
                "height": "56"
              },
              "additional_properties": {
                "id": "d0361002-8f43-4196-be54-e22e2a6f7e49",
                "numbered": false,
                "edge": false,
                "service_type": "loadbalancer",
                "generic_type": "Services::Network::LoadBalancer",
                "type": "Services::Network::LoadBalancer::AWS",
                "name": "ELBV20MAZ##",
                "drawable": true,
                "draggable": true,
                "interfaces": [
                  {
                    "id": "a474c91f-d0ac-4570-bd50-dedf8ded7efb",
                    "name": "default",
                    "depends": true,
                    "connections": [
                      {
                        "id": "dc14dc18-6fcc-4ae4-afb2-b34b02835c2a",
                        "interface_id": "a474c91f-d0ac-4570-bd50-dedf8ded7efb",
                        "remote_interface_id": "94c76455-6f91-4128-8db1-d90b323cf891",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::SecurityGroup"
                  },
                  {
                    "id": "6b6d3835-03e6-49f4-b43e-0543381e3bf0",
                    "name": "loadbalancer",
                    "depends": false,
                    "connections": null,
                    "interface_type": "Protocols::LoadBalancer"
                  },
                  {
                    "id": "6a446566-a458-4af8-b6d5-97421c4bc237",
                    "name": "prat",
                    "depends": true,
                    "connections": [
                      {
                        "id": "49a3e643-173c-4e53-8766-ae987a5126cb",
                        "interface_id": "6a446566-a458-4af8-b6d5-97421c4bc237",
                        "remote_interface_id": "cbb835cb-991c-4b69-a081-195c597025bf",
                        "type": "implicit"
                      }
                    ],
                    "interface_type": "Protocols::Vpc"
                  },
                  {
                    "id": "cbb03ea8-85d0-42ce-a499-4f293e532b43",
                    "name": "SN-V20-$$servername-##",
                    "depends": true,
                    "connections": [
                      {
                        "id": "05ec56c6-184a-4d38-9ef6-2bff05356cae",
                        "interface_id": "cbb03ea8-85d0-42ce-a499-4f293e532b43",
                        "remote_interface_id": "00a5f387-643e-46bb-949c-3062b5bfebec",
                        "type": "explicit"
                      },
                      {
                        "id": "f8820a4d-61c9-48b8-9c5a-69c15f261dd0",
                        "interface_id": "cbb03ea8-85d0-42ce-a499-4f293e532b43",
                        "remote_interface_id": "ca42bb6b-4723-45b5-bcf6-544b1779edca",
                        "type": "explicit"
                      }
                    ],
                    "interface_type": "Protocols::Subnet"
                  }
                ],
                "vpc_id": "16ef4f23-56e2-4f45-a2e3-f20e907bbfcf",
                "primary_key": "",
                "provides": [
                  {
                    "name": "LoadBalancer",
                    "type": "Protocols::LoadBalancer",
                    "limit": "1",
                    "max_connections": "1024",
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
                    "name": "frontend",
                    "type": "Protocols::IP",
                    "limit": "1",
                    "max_connections": "2",
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
                "parent_id": "9d27f097-3fe8-4907-b8aa-305ba973b6da",
                "properties": [
                  {
                    "name": "hcheck_interval",
                    "value": "30",
                    "form_options": {
                      "type": "text",
                      "unitLabel": "seconds"
                    },
                    "title": "Health Check Interval"
                  },
                  {
                    "name": "response_timeout",
                    "value": "5",
                    "form_options": {
                      "type": "text",
                      "unitLabel": "seconds"
                    },
                    "title": "Response Timeout"
                  },
                  {
                    "name": "unhealthy_threshold",
                    "value": "4",
                    "form_options": {
                      "type": "range",
                      "min": "2",
                      "max": "10",
                      "step": "1"
                    },
                    "title": "Unhealthy Threshold"
                  },
                  {
                    "name": "healthy_threshold",
                    "value": "9",
                    "form_options": {
                      "type": "range",
                      "min": "2",
                      "max": "10",
                      "step": "1"
                    },
                    "title": "Healthy Threshold"
                  },
                  {
                    "name": "dns_name",
                    "form_options": {
                      "type": "hidden",
                      "readonly": "readonly"
                    },
                    "title": "DNS Name"
                  },
                  {
                    "name": "scheme",
                    "value": "internal",
                    "form_options": {
                      "type": "select",
                      "options": [
                        "internal",
                        "internet-facing"
                      ]
                    },
                    "title": "Scheme"
                  },
                  {
                    "name": "ping_protocol",
                    "value": "HTTP",
                    "form_options": {
                      "type": "select",
                      "options": [
                        "HTTP",
                        "TCP",
                        "HTTPS",
                        "SSL"
                      ],
                      "depends_on": true
                    },
                    "title": "Ping Protocol"
                  },
                  {
                    "name": "ping_protocol_port",
                    "value": "80",
                    "form_options": {
                      "type": "text"
                    },
                    "title": "Ping Port"
                  },
                  {
                    "name": "ping_path",
                    "value": "index.html",
                    "form_options": {
                      "type": "text",
                      "depends_on": true
                    },
                    "title": "Ping Path"
                  },
                  {
                    "name": "listeners",
                    "value": [
                      {
                        "Listener": {
                          "instance_port": "80",
                          "instance_protocol": "HTTP",
                          "lb_port": "80",
                          "protocol": "HTTP"
                        }
                      }
                    ],
                    "form_options": {
                      "type": "lblistner"
                    },
                    "title": "listeners"
                  },
                  {
                    "name": "cross_zone_load_balancing",
                    "value": false,
                    "form_options": {
                      "type": "checkbox"
                    },
                    "title": "Cross Zone"
                  },
                  {
                    "name": "connection_draining",
                    "value": false,
                    "form_options": {
                      "type": "checkbox"
                    },
                    "title": "Connection Draining"
                  },
                  {
                    "name": "connection_draining_timeout",
                    "value": "60",
                    "form_options": {
                      "type": "text",
                      "unitLabel": "seconds",
                      "depends_on": false
                    },
                    "title": "Connection Draining Timeout"
                  },
                  {
                    "name": "connection_timeout",
                    "value": "300",
                    "form_options": {
                      "type": "text",
                      "unitLabel": "seconds"
                    },
                    "title": "Idle Connection Timeout"
                  },
                  {
                    "name": "ssl_certificate",
                    "value": {
                      "Path": "/",
                      "UploadDate": "2016-08-17T12:19:24.000Z",
                      "ServerCertificateName": "aaaa",
                      "Arn": "arn:aws:iam::707082674943:server-certificate/aaaa",
                      "ServerCertificateId": "ASCAJ7RP7K4HLGJUD4J6A"
                    },
                    "form_options": {
                      "type": "select",
                      "options": [
                        {
                          "Path": "/",
                          "UploadDate": "2016-08-17T12:19:24.000Z",
                          "ServerCertificateName": "aaaa",
                          "Arn": "arn:aws:iam::707082674943:server-certificate/aaaa",
                          "ServerCertificateId": "ASCAJ7RP7K4HLGJUD4J6A"
                        },
                        {
                          "Path": "/",
                          "UploadDate": "2016-08-23T13:43:38.000Z",
                          "ServerCertificateName": "ajinkya",
                          "Arn": "arn:aws:iam::707082674943:server-certificate/ajinkya",
                          "ServerCertificateId": "ASCAJEXWG65KGJ2MMX4HS"
                        },
                        {
                          "Path": "/",
                          "UploadDate": "2016-08-17T10:49:09.000Z",
                          "ServerCertificateName": "asdas",
                          "Arn": "arn:aws:iam::707082674943:server-certificate/asdas",
                          "ServerCertificateId": "ASCAITNGLJGOLWKDYHOMI"
                        },
                        {
                          "Path": "/",
                          "UploadDate": "2016-08-18T09:30:03.000Z",
                          "ServerCertificateName": "ass",
                          "Arn": "arn:aws:iam::707082674943:server-certificate/ass",
                          "ServerCertificateId": "ASCAJDNGU2275L4U54FVM"
                        },
                        {
                          "Path": "/",
                          "UploadDate": "2016-08-18T06:35:42.000Z",
                          "ServerCertificateName": "demo",
                          "Arn": "arn:aws:iam::707082674943:server-certificate/demo",
                          "ServerCertificateId": "ASCAJTZYDVMRGMOVDM2XG"
                        },
                        {
                          "Path": "/",
                          "UploadDate": "2016-08-17T10:48:09.000Z",
                          "ServerCertificateName": "dsad",
                          "Arn": "arn:aws:iam::707082674943:server-certificate/dsad",
                          "ServerCertificateId": "ASCAIXOU2N3JKZ57MV3J2"
                        },
                        {
                          "Path": "/",
                          "UploadDate": "2016-08-18T09:45:49.000Z",
                          "ServerCertificateName": "kumo",
                          "Arn": "arn:aws:iam::707082674943:server-certificate/kumo",
                          "ServerCertificateId": "ASCAI7QFSLGGUWTJ5G4DY"
                        },
                        {
                          "Path": "/",
                          "UploadDate": "2016-06-24T13:14:06.000Z",
                          "ServerCertificateName": "mycer",
                          "Arn": "arn:aws:iam::707082674943:server-certificate/mycer",
                          "ServerCertificateId": "ASCAJVCKI66AJSI65CUCS"
                        },
                        {
                          "Path": "/",
                          "UploadDate": "2016-08-18T09:14:59.000Z",
                          "ServerCertificateName": "test",
                          "Arn": "arn:aws:iam::707082674943:server-certificate/test",
                          "ServerCertificateId": "ASCAIYYYYBJPTXI5VFXCI"
                        }
                      ],
                      "data": [
                        {
                          "Path": "/",
                          "UploadDate": "2016-08-17T12:19:24.000Z",
                          "ServerCertificateName": "aaaa",
                          "Arn": "arn:aws:iam::707082674943:server-certificate/aaaa",
                          "ServerCertificateId": "ASCAJ7RP7K4HLGJUD4J6A"
                        },
                        {
                          "Path": "/",
                          "UploadDate": "2016-08-23T13:43:38.000Z",
                          "ServerCertificateName": "ajinkya",
                          "Arn": "arn:aws:iam::707082674943:server-certificate/ajinkya",
                          "ServerCertificateId": "ASCAJEXWG65KGJ2MMX4HS"
                        },
                        {
                          "Path": "/",
                          "UploadDate": "2016-08-17T10:49:09.000Z",
                          "ServerCertificateName": "asdas",
                          "Arn": "arn:aws:iam::707082674943:server-certificate/asdas",
                          "ServerCertificateId": "ASCAITNGLJGOLWKDYHOMI"
                        },
                        {
                          "Path": "/",
                          "UploadDate": "2016-08-18T09:30:03.000Z",
                          "ServerCertificateName": "ass",
                          "Arn": "arn:aws:iam::707082674943:server-certificate/ass",
                          "ServerCertificateId": "ASCAJDNGU2275L4U54FVM"
                        },
                        {
                          "Path": "/",
                          "UploadDate": "2016-08-18T06:35:42.000Z",
                          "ServerCertificateName": "demo",
                          "Arn": "arn:aws:iam::707082674943:server-certificate/demo",
                          "ServerCertificateId": "ASCAJTZYDVMRGMOVDM2XG"
                        },
                        {
                          "Path": "/",
                          "UploadDate": "2016-08-17T10:48:09.000Z",
                          "ServerCertificateName": "dsad",
                          "Arn": "arn:aws:iam::707082674943:server-certificate/dsad",
                          "ServerCertificateId": "ASCAIXOU2N3JKZ57MV3J2"
                        },
                        {
                          "Path": "/",
                          "UploadDate": "2016-08-18T09:45:49.000Z",
                          "ServerCertificateName": "kumo",
                          "Arn": "arn:aws:iam::707082674943:server-certificate/kumo",
                          "ServerCertificateId": "ASCAI7QFSLGGUWTJ5G4DY"
                        },
                        {
                          "Path": "/",
                          "UploadDate": "2016-06-24T13:14:06.000Z",
                          "ServerCertificateName": "mycer",
                          "Arn": "arn:aws:iam::707082674943:server-certificate/mycer",
                          "ServerCertificateId": "ASCAJVCKI66AJSI65CUCS"
                        },
                        {
                          "Path": "/",
                          "UploadDate": "2016-08-18T09:14:59.000Z",
                          "ServerCertificateName": "test",
                          "Arn": "arn:aws:iam::707082674943:server-certificate/test",
                          "ServerCertificateId": "ASCAIYYYYBJPTXI5VFXCI"
                        }
                      ]
                    },
                    "title": "SSL Certificate"
                  },
                  {
                    "name": "new_ssl_certificate",
                    "form_options": {
                      "type": "text",
                      "depends_on": false
                    },
                    "title": "New SSL Certificate"
                  }
                ]
              },
              "properties": [
                {
                  "name": "hcheck_interval",
                  "value": "30",
                  "form_options": {
                    "type": "text",
                    "unitLabel": "seconds"
                  },
                  "title": "Health Check Interval"
                },
                {
                  "name": "response_timeout",
                  "value": "5",
                  "form_options": {
                    "type": "text",
                    "unitLabel": "seconds"
                  },
                  "title": "Response Timeout"
                },
                {
                  "name": "unhealthy_threshold",
                  "value": "4",
                  "form_options": {
                    "type": "range",
                    "min": "2",
                    "max": "10",
                    "step": "1"
                  },
                  "title": "Unhealthy Threshold"
                },
                {
                  "name": "healthy_threshold",
                  "value": "9",
                  "form_options": {
                    "type": "range",
                    "min": "2",
                    "max": "10",
                    "step": "1"
                  },
                  "title": "Healthy Threshold"
                },
                {
                  "name": "dns_name",
                  "form_options": {
                    "type": "hidden",
                    "readonly": "readonly"
                  },
                  "title": "DNS Name"
                },
                {
                  "name": "scheme",
                  "value": "internal",
                  "form_options": {
                    "type": "select",
                    "options": [
                      "internal",
                      "internet-facing"
                    ]
                  },
                  "title": "Scheme"
                },
                {
                  "name": "ping_protocol",
                  "value": "HTTP",
                  "form_options": {
                    "type": "select",
                    "options": [
                      "HTTP",
                      "TCP",
                      "HTTPS",
                      "SSL"
                    ],
                    "depends_on": true
                  },
                  "title": "Ping Protocol"
                },
                {
                  "name": "ping_protocol_port",
                  "value": "80",
                  "form_options": {
                    "type": "text"
                  },
                  "title": "Ping Port"
                },
                {
                  "name": "ping_path",
                  "value": "index.html",
                  "form_options": {
                    "type": "text",
                    "depends_on": true
                  },
                  "title": "Ping Path"
                },
                {
                  "name": "listeners",
                  "value": [
                    {
                      "Listener": {
                        "instance_port": "80",
                        "instance_protocol": "HTTP",
                        "lb_port": "80",
                        "protocol": "HTTP"
                      }
                    }
                  ],
                  "form_options": {
                    "type": "lblistner"
                  },
                  "title": "listeners"
                },
                {
                  "name": "cross_zone_load_balancing",
                  "value": false,
                  "form_options": {
                    "type": "checkbox"
                  },
                  "title": "Cross Zone"
                },
                {
                  "name": "connection_draining",
                  "value": false,
                  "form_options": {
                    "type": "checkbox"
                  },
                  "title": "Connection Draining"
                },
                {
                  "name": "connection_draining_timeout",
                  "value": "60",
                  "form_options": {
                    "type": "text",
                    "unitLabel": "seconds",
                    "depends_on": false
                  },
                  "title": "Connection Draining Timeout"
                },
                {
                  "name": "connection_timeout",
                  "value": "300",
                  "form_options": {
                    "type": "text",
                    "unitLabel": "seconds"
                  },
                  "title": "Idle Connection Timeout"
                },
                {
                  "name": "ssl_certificate",
                  "value": {
                    "Path": "/",
                    "UploadDate": "2016-08-17T12:19:24.000Z",
                    "ServerCertificateName": "aaaa",
                    "Arn": "arn:aws:iam::707082674943:server-certificate/aaaa",
                    "ServerCertificateId": "ASCAJ7RP7K4HLGJUD4J6A"
                  },
                  "form_options": {
                    "type": "select",
                    "options": [
                      {
                        "Path": "/",
                        "UploadDate": "2016-08-17T12:19:24.000Z",
                        "ServerCertificateName": "aaaa",
                        "Arn": "arn:aws:iam::707082674943:server-certificate/aaaa",
                        "ServerCertificateId": "ASCAJ7RP7K4HLGJUD4J6A"
                      },
                      {
                        "Path": "/",
                        "UploadDate": "2016-08-23T13:43:38.000Z",
                        "ServerCertificateName": "ajinkya",
                        "Arn": "arn:aws:iam::707082674943:server-certificate/ajinkya",
                        "ServerCertificateId": "ASCAJEXWG65KGJ2MMX4HS"
                      },
                      {
                        "Path": "/",
                        "UploadDate": "2016-08-17T10:49:09.000Z",
                        "ServerCertificateName": "asdas",
                        "Arn": "arn:aws:iam::707082674943:server-certificate/asdas",
                        "ServerCertificateId": "ASCAITNGLJGOLWKDYHOMI"
                      },
                      {
                        "Path": "/",
                        "UploadDate": "2016-08-18T09:30:03.000Z",
                        "ServerCertificateName": "ass",
                        "Arn": "arn:aws:iam::707082674943:server-certificate/ass",
                        "ServerCertificateId": "ASCAJDNGU2275L4U54FVM"
                      },
                      {
                        "Path": "/",
                        "UploadDate": "2016-08-18T06:35:42.000Z",
                        "ServerCertificateName": "demo",
                        "Arn": "arn:aws:iam::707082674943:server-certificate/demo",
                        "ServerCertificateId": "ASCAJTZYDVMRGMOVDM2XG"
                      },
                      {
                        "Path": "/",
                        "UploadDate": "2016-08-17T10:48:09.000Z",
                        "ServerCertificateName": "dsad",
                        "Arn": "arn:aws:iam::707082674943:server-certificate/dsad",
                        "ServerCertificateId": "ASCAIXOU2N3JKZ57MV3J2"
                      },
                      {
                        "Path": "/",
                        "UploadDate": "2016-08-18T09:45:49.000Z",
                        "ServerCertificateName": "kumo",
                        "Arn": "arn:aws:iam::707082674943:server-certificate/kumo",
                        "ServerCertificateId": "ASCAI7QFSLGGUWTJ5G4DY"
                      },
                      {
                        "Path": "/",
                        "UploadDate": "2016-06-24T13:14:06.000Z",
                        "ServerCertificateName": "mycer",
                        "Arn": "arn:aws:iam::707082674943:server-certificate/mycer",
                        "ServerCertificateId": "ASCAJVCKI66AJSI65CUCS"
                      },
                      {
                        "Path": "/",
                        "UploadDate": "2016-08-18T09:14:59.000Z",
                        "ServerCertificateName": "test",
                        "Arn": "arn:aws:iam::707082674943:server-certificate/test",
                        "ServerCertificateId": "ASCAIYYYYBJPTXI5VFXCI"
                      }
                    ],
                    "data": [
                      {
                        "Path": "/",
                        "UploadDate": "2016-08-17T12:19:24.000Z",
                        "ServerCertificateName": "aaaa",
                        "Arn": "arn:aws:iam::707082674943:server-certificate/aaaa",
                        "ServerCertificateId": "ASCAJ7RP7K4HLGJUD4J6A"
                      },
                      {
                        "Path": "/",
                        "UploadDate": "2016-08-23T13:43:38.000Z",
                        "ServerCertificateName": "ajinkya",
                        "Arn": "arn:aws:iam::707082674943:server-certificate/ajinkya",
                        "ServerCertificateId": "ASCAJEXWG65KGJ2MMX4HS"
                      },
                      {
                        "Path": "/",
                        "UploadDate": "2016-08-17T10:49:09.000Z",
                        "ServerCertificateName": "asdas",
                        "Arn": "arn:aws:iam::707082674943:server-certificate/asdas",
                        "ServerCertificateId": "ASCAITNGLJGOLWKDYHOMI"
                      },
                      {
                        "Path": "/",
                        "UploadDate": "2016-08-18T09:30:03.000Z",
                        "ServerCertificateName": "ass",
                        "Arn": "arn:aws:iam::707082674943:server-certificate/ass",
                        "ServerCertificateId": "ASCAJDNGU2275L4U54FVM"
                      },
                      {
                        "Path": "/",
                        "UploadDate": "2016-08-18T06:35:42.000Z",
                        "ServerCertificateName": "demo",
                        "Arn": "arn:aws:iam::707082674943:server-certificate/demo",
                        "ServerCertificateId": "ASCAJTZYDVMRGMOVDM2XG"
                      },
                      {
                        "Path": "/",
                        "UploadDate": "2016-08-17T10:48:09.000Z",
                        "ServerCertificateName": "dsad",
                        "Arn": "arn:aws:iam::707082674943:server-certificate/dsad",
                        "ServerCertificateId": "ASCAIXOU2N3JKZ57MV3J2"
                      },
                      {
                        "Path": "/",
                        "UploadDate": "2016-08-18T09:45:49.000Z",
                        "ServerCertificateName": "kumo",
                        "Arn": "arn:aws:iam::707082674943:server-certificate/kumo",
                        "ServerCertificateId": "ASCAI7QFSLGGUWTJ5G4DY"
                      },
                      {
                        "Path": "/",
                        "UploadDate": "2016-06-24T13:14:06.000Z",
                        "ServerCertificateName": "mycer",
                        "Arn": "arn:aws:iam::707082674943:server-certificate/mycer",
                        "ServerCertificateId": "ASCAJVCKI66AJSI65CUCS"
                      },
                      {
                        "Path": "/",
                        "UploadDate": "2016-08-18T09:14:59.000Z",
                        "ServerCertificateName": "test",
                        "Arn": "arn:aws:iam::707082674943:server-certificate/test",
                        "ServerCertificateId": "ASCAIYYYYBJPTXI5VFXCI"
                      }
                    ]
                  },
                  "title": "SSL Certificate"
                },
                {
                  "name": "new_ssl_certificate",
                  "form_options": {
                    "type": "text",
                    "depends_on": false
                  },
                  "title": "New SSL Certificate"
                }
              ],
              "interfaces": [
                {
                  "id": "a474c91f-d0ac-4570-bd50-dedf8ded7efb",
                  "name": "default",
                  "interface_type": "Protocols::SecurityGroup",
                  "depends": true,
                  "connections": [
                    {
                      "id": "dc14dc18-6fcc-4ae4-afb2-b34b02835c2a",
                      "interface_id": "a474c91f-d0ac-4570-bd50-dedf8ded7efb",
                      "remote_interface_id": "94c76455-6f91-4128-8db1-d90b323cf891",
                      "internal": false
                    }
                  ]
                },
                {
                  "id": "6b6d3835-03e6-49f4-b43e-0543381e3bf0",
                  "name": "loadbalancer",
                  "interface_type": "Protocols::LoadBalancer",
                  "depends": false,
                  "connections": []
                },
                {
                  "id": "6a446566-a458-4af8-b6d5-97421c4bc237",
                  "name": "prat",
                  "interface_type": "Protocols::Vpc",
                  "depends": true,
                  "connections": [
                    {
                      "id": "49a3e643-173c-4e53-8766-ae987a5126cb",
                      "interface_id": "6a446566-a458-4af8-b6d5-97421c4bc237",
                      "remote_interface_id": "cbb835cb-991c-4b69-a081-195c597025bf",
                      "internal": false
                    }
                  ]
                },
                {
                  "id": "cbb03ea8-85d0-42ce-a499-4f293e532b43",
                  "name": "SN-V20-$$servername-##",
                  "interface_type": "Protocols::Subnet",
                  "depends": true,
                  "connections": [
                    {
                      "id": "05ec56c6-184a-4d38-9ef6-2bff05356cae",
                      "interface_id": "cbb03ea8-85d0-42ce-a499-4f293e532b43",
                      "remote_interface_id": "00a5f387-643e-46bb-949c-3062b5bfebec",
                      "internal": false
                    },
                    {
                      "id": "f8820a4d-61c9-48b8-9c5a-69c15f261dd0",
                      "interface_id": "cbb03ea8-85d0-42ce-a499-4f293e532b43",
                      "remote_interface_id": "ca42bb6b-4723-45b5-bcf6-544b1779edca",
                      "internal": false
                    }
                  ]
                }
              ],
              "provides": [
                {
                  "name": "LoadBalancer",
                  "type": "Protocols::LoadBalancer",
                  "limit": "1",
                  "max_connections": "1024",
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
                  "name": "frontend",
                  "type": "Protocols::IP",
                  "limit": "1",
                  "max_connections": "2",
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
              ]
            }
          ],
          "_links": {
            "provision": {
              "href": "/templates/55c48e1f-531a-4d78-9c6d-d68d7a7ea7c7/provision"
            },
            "clone": {
              "href": "/templates/55c48e1f-531a-4d78-9c6d-d68d7a7ea7c7/clone"
            },
            "self": {
              "href": "/templates/55c48e1f-531a-4d78-9c6d-d68d7a7ea7c7/destroy"
            },
            "remove": {
              "href": "/templates/55c48e1f-531a-4d78-9c6d-d68d7a7ea7c7/destroy"
            },
            "edit": {
              "href": "/templates/55c48e1f-531a-4d78-9c6d-d68d7a7ea7c7/destroy"
            }
          }
        }';
      }
      */
    

     /*$jsonString = '{
        "name": "vpc-c1e86ca6",
        "state": "pending",
        "adapter_id": "3bc8c44b-4108-4d99-bb0c-ae51c4cef53f",
        "region_id": "8f5d47a6-7c27-406d-80de-66c6bf18efc1",
        "created_at": "2016-11-23 11:02 UTC",
        "updated_at": "2016-11-23 11:02 UTC",
        "adapter_type": "AWS",
        "adapter_name": "pagal adapter",
        "region_name": "US East (N. Virginia)",
        "shared_with": [],
        "tags_map": {
          "Name": [
            "prat"
          ]
        },
        "description": "",
        "services": [
          {
            "id": "175403a3-e268-49f9-8a17-9c231e56bf82",
            "name": "prat",
            "type": "Services::Vpc",
            "additional_properties": {
              "parent_id": null,
              "depends": [],
              "draggable": true,
              "drawable": true,
              "edge": false,
              "generic_type": "Services::Vpc",
              "id": "175403a3-e268-49f9-8a17-9c231e56bf82",
              "interfaces": [
                {
                  "id": "09b1def7-385b-4771-a485-e086d9a21953",
                  "service_id": "175403a3-e268-49f9-8a17-9c231e56bf82",
                  "name": "prat",
                  "interface_type": "Protocols::Vpc",
                  "created_at": "2016-08-18T10:37:04.839Z",
                  "updated_at": "2016-08-18T10:37:04.839Z",
                  "depends": false,
                  "connections": []
                }
              ],
              "name": "prat",
              "state": "running",
              "primary_key": "abe705b7-a41b-4bc5-9b3d-44fb656ee3d4",
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
                  "value": "vpc-c1e86ca6",
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
                  "value": "igw-f3d1f497",
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
              "vpc_id": "vpc-c1e86ca6"
            },
            "tags": {
              "Name": "prat"
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
                "value": "vpc-c1e86ca6",
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
                "value": "igw-f3d1f497",
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
                "id": "09b1def7-385b-4771-a485-e086d9a21953",
                "name": "prat",
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
            "id": "d7e2c3ef-19f5-4ff1-889c-fe4279afb73b",
            "name": "rtb-f43c3593",
            "type": "Services::Network::RouteTable::AWS",
            "additional_properties": {
              "parent_id": "175403a3-e268-49f9-8a17-9c231e56bf82",
              "depends": [],
              "draggable": false,
              "drawable": true,
              "edge": false,
              "generic_type": "Services::Network::RouteTable",
              "id": "d7e2c3ef-19f5-4ff1-889c-fe4279afb73b",
              "interfaces": [
                {
                  "id": "d02bb176-46f0-40a1-902b-c8e3c79cf94b",
                  "service_id": "d7e2c3ef-19f5-4ff1-889c-fe4279afb73b",
                  "name": "rtb-f43c3593",
                  "interface_type": "Protocols::RouteTable",
                  "created_at": "2016-08-18T10:37:04.845Z",
                  "updated_at": "2016-08-18T10:37:04.845Z",
                  "depends": false,
                  "connections": []
                },
                {
                  "id": "58735c2b-6fc3-4f5a-8526-2e407c1ecb9f",
                  "service_id": "d7e2c3ef-19f5-4ff1-889c-fe4279afb73b",
                  "name": null,
                  "interface_type": "Protocols::Vpc",
                  "created_at": "2016-08-18T10:37:04.869Z",
                  "updated_at": "2016-08-18T10:37:04.869Z",
                  "depends": true,
                  "connections": [
                    {
                      "id": "15b69a57-0769-489c-92e1-ebb58703ddc1",
                      "interface_id": "58735c2b-6fc3-4f5a-8526-2e407c1ecb9f",
                      "remote_interface_id": "09b1def7-385b-4771-a485-e086d9a21953",
                      "created_at": "2016-08-18T10:37:04.873Z",
                      "updated_at": "2016-08-18T10:37:04.873Z",
                      "internal": false
                    }
                  ]
                }
              ],
              "name": "rtb-f43c3593",
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
                  "value": "rtb-f43c3593",
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
                      "gatewayId": "igw-f3d1f497",
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
              "vpc_id": "abe705b7-a41b-4bc5-9b3d-44fb656ee3d4"
            },
            "tags": {},
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
                "value": "rtb-f43c3593",
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
                    "gatewayId": "igw-f3d1f497",
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
                "id": "d02bb176-46f0-40a1-902b-c8e3c79cf94b",
                "name": "rtb-f43c3593",
                "interface_type": "Protocols::RouteTable",
                "depends": false,
                "connections": []
              },
              {
                "id": "58735c2b-6fc3-4f5a-8526-2e407c1ecb9f",
                "interface_type": "Protocols::Vpc",
                "depends": true,
                "connections": [
                  {
                    "id": "15b69a57-0769-489c-92e1-ebb58703ddc1",
                    "interface_id": "58735c2b-6fc3-4f5a-8526-2e407c1ecb9f",
                    "remote_interface_id": "09b1def7-385b-4771-a485-e086d9a21953",
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
            "id": "7624a460-820c-4ede-a856-7d1a35b79f19",
            "name": "igw-f3d1f497",
            "type": "Services::Network::InternetGateway::AWS",
            "additional_properties": {
              "parent_id": "175403a3-e268-49f9-8a17-9c231e56bf82",
              "depends": [],
              "draggable": false,
              "drawable": true,
              "edge": false,
              "generic_type": "Services::Network::InternetGateway",
              "id": "7624a460-820c-4ede-a856-7d1a35b79f19",
              "interfaces": [
                {
                  "id": "c92aa6b9-b269-4aec-8334-93a78ccd7fd0",
                  "service_id": "7624a460-820c-4ede-a856-7d1a35b79f19",
                  "name": "igw-f3d1f497",
                  "interface_type": "Protocols::InternetGateway",
                  "created_at": "2016-08-18T10:37:04.851Z",
                  "updated_at": "2016-08-18T10:37:04.851Z",
                  "depends": false,
                  "connections": []
                },
                {
                  "id": "d90e14bb-b007-4687-8059-73f2685d1523",
                  "service_id": "7624a460-820c-4ede-a856-7d1a35b79f19",
                  "name": null,
                  "interface_type": "Protocols::RouteTable",
                  "created_at": "2016-08-18T10:37:04.887Z",
                  "updated_at": "2016-08-18T10:37:04.887Z",
                  "depends": true,
                  "connections": [
                    {
                      "id": "dcfe8d45-8eac-4fc3-8727-39478549cf4a",
                      "interface_id": "d90e14bb-b007-4687-8059-73f2685d1523",
                      "remote_interface_id": "d02bb176-46f0-40a1-902b-c8e3c79cf94b",
                      "created_at": "2016-08-18T10:37:04.890Z",
                      "updated_at": "2016-08-18T10:37:04.890Z",
                      "internal": false
                    }
                  ]
                },
                {
                  "id": "bf90ac39-e603-4427-a898-4b1c707df5e5",
                  "service_id": "7624a460-820c-4ede-a856-7d1a35b79f19",
                  "name": null,
                  "interface_type": "Protocols::Vpc",
                  "created_at": "2016-08-18T10:37:05.087Z",
                  "updated_at": "2016-08-18T10:37:05.087Z",
                  "depends": true,
                  "connections": [
                    {
                      "id": "bd53b243-d565-4682-93e8-67bc075249a7",
                      "interface_id": "bf90ac39-e603-4427-a898-4b1c707df5e5",
                      "remote_interface_id": "09b1def7-385b-4771-a485-e086d9a21953",
                      "created_at": "2016-08-18T10:37:05.090Z",
                      "updated_at": "2016-08-18T10:37:05.090Z",
                      "internal": false
                    }
                  ]
                }
              ],
              "name": "igw-f3d1f497",
              "state": "running",
              "primary_key": null,
              "properties": [
                {
                  "name": "internet_gateway_id",
                  "value": "igw-f3d1f497",
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
              "vpc_id": "abe705b7-a41b-4bc5-9b3d-44fb656ee3d4"
            },
            "tags": {},
            "properties": [
              {
                "name": "internet_gateway_id",
                "value": "igw-f3d1f497",
                "form_options": {
                  "type": "hidden"
                },
                "title": "Internet Gateway Id"
              }
            ],
            "interfaces": [
              {
                "id": "c92aa6b9-b269-4aec-8334-93a78ccd7fd0",
                "name": "igw-f3d1f497",
                "interface_type": "Protocols::InternetGateway",
                "depends": false,
                "connections": []
              },
              {
                "id": "d90e14bb-b007-4687-8059-73f2685d1523",
                "interface_type": "Protocols::RouteTable",
                "depends": true,
                "connections": [
                  {
                    "id": "dcfe8d45-8eac-4fc3-8727-39478549cf4a",
                    "interface_id": "d90e14bb-b007-4687-8059-73f2685d1523",
                    "remote_interface_id": "d02bb176-46f0-40a1-902b-c8e3c79cf94b",
                    "internal": false
                  }
                ]
              },
              {
                "id": "bf90ac39-e603-4427-a898-4b1c707df5e5",
                "interface_type": "Protocols::Vpc",
                "depends": true,
                "connections": [
                  {
                    "id": "bd53b243-d565-4682-93e8-67bc075249a7",
                    "interface_id": "bf90ac39-e603-4427-a898-4b1c707df5e5",
                    "remote_interface_id": "09b1def7-385b-4771-a485-e086d9a21953",
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
          }
        ],
        "_links": {}
      }';*/

     /*$jsonString = '{
        "id": "0dede1d3-6ffb-481a-b732-5ceb337f4b7d",
        "name": "c1",
        "state": "pending",
        "template_model": {
          "scale": "1"
        },
        "adapter_id": "906b379e-3f78-4ade-bc64-587a8919bd78",
        "region_id": "b0459d7c-ea81-49b2-ad43-1dbb2d82ac02",
        "created_by": "Demo",
        "created_at": "2016-11-10 10:22 UTC",
        "updated_by": "Demo",
        "updated_at": "2016-11-10 11:20 UTC",
        "adapter_type": "AWS",
        "adapter_name": "pratik-067",
        "region_name": "Asia Pacific (Singapore)",
        "shared_with": [
          "6b5d1956-5b10-4ad2-bb16-a78bb7f6dd20",
          "c2c73ba0-f453-47fd-98be-1bf2c095730d"
        ],
        "description": "",
        "services": [
          {
            "id": "5fdc0cf0-3dac-4074-a1d8-c811d3891314",
            "name": "atit-VPC",
            "type": "Services::Vpc",
            "geometry": {
              "x": "24",
              "y": "24",
              "width": "628",
              "height": "362"
            },
            "additional_properties": {
              "id": "5fdc0cf0-3dac-4074-a1d8-c811d3891314",
              "edge": false,
              "service_type": "vpc",
              "generic_type": "Services::Vpc",
              "type": "Services::Vpc",
              "name": "atit-VPC",
              "drawable": true,
              "draggable": true,
              "interfaces": [
                {
                  "id": "30f34d56-7767-4f6e-b3d9-e138fc5d21e5",
                  "name": "atit-VPC",
                  "depends": false,
                  "connections": null,
                  "interface_type": "Protocols::Vpc"
                }
              ],
              "vpc_id": "vpc-d10bdbb5",
              "primary_key": "b4d269a4-49c5-42bf-b7c1-2818118fe6a7",
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
              "depends": null,
              "parent_id": null,
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
                  "value": true,
                  "form_options": {
                    "type": "hidden"
                  },
                  "title": "Enable Dns Resolution"
                },
                {
                  "name": "enable_dns_hostnames",
                  "value": false,
                  "form_options": {
                    "type": "hidden"
                  },
                  "title": "Enable Dns Hostnames"
                }
              ]
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
                "value": true,
                "form_options": {
                  "type": "hidden"
                },
                "title": "Enable Dns Resolution"
              },
              {
                "name": "enable_dns_hostnames",
                "value": false,
                "form_options": {
                  "type": "hidden"
                },
                "title": "Enable Dns Hostnames"
              }
            ],
            "interfaces": [
              {
                "id": "30f34d56-7767-4f6e-b3d9-e138fc5d21e5",
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
            "id": "5a3ce7b0-bd17-4a96-8ed5-396103a41123",
            "name": "atit-private-sec-grp",
            "type": "Services::Network::SecurityGroup::AWS",
            "geometry": {
              "x": "24",
              "y": "24",
              "width": "56",
              "height": "56"
            },
            "additional_properties": {
              "id": "5a3ce7b0-bd17-4a96-8ed5-396103a41123",
              "edge": false,
              "service_type": "securitygroup",
              "generic_type": "Services::Network::SecurityGroup",
              "type": "Services::Network::SecurityGroup::AWS",
              "name": "atit-private-sec-grp",
              "drawable": false,
              "draggable": false,
              "interfaces": [
                {
                  "id": "35987ec9-22a8-485b-bda1-e7a561d8f097",
                  "name": "atit-private-sec-grp",
                  "depends": false,
                  "connections": null,
                  "interface_type": "Protocols::SecurityGroup"
                },
                {
                  "id": "34fe22e8-7435-4f89-9b9b-bc265078d30c",
                  "name": null,
                  "depends": true,
                  "connections": [
                    {
                      "id": "8ed2306e-23f8-4c9b-925c-474548a2d710",
                      "interface_id": "34fe22e8-7435-4f89-9b9b-bc265078d30c",
                      "remote_interface_id": "30f34d56-7767-4f6e-b3d9-e138fc5d21e5",
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
              "parent_id": "5fdc0cf0-3dac-4074-a1d8-c811d3891314",
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
                      "groups": null,
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
                      "groups": null,
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
                      "groups": null,
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
                      "groups": null,
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
              ]
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
                    "groups": null,
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
                    "groups": null,
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
                    "groups": null,
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
                    "groups": null,
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
                "id": "35987ec9-22a8-485b-bda1-e7a561d8f097",
                "name": "atit-private-sec-grp",
                "interface_type": "Protocols::SecurityGroup",
                "depends": false,
                "connections": []
              },
              {
                "id": "34fe22e8-7435-4f89-9b9b-bc265078d30c",
                "interface_type": "Protocols::Vpc",
                "depends": true,
                "connections": [
                  {
                    "id": "8ed2306e-23f8-4c9b-925c-474548a2d710",
                    "interface_id": "34fe22e8-7435-4f89-9b9b-bc265078d30c",
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
            "id": "7f0e69f9-01c8-4616-b50d-4979c24a2eb0",
            "name": " atit-sec-group-1",
            "type": "Services::Network::SecurityGroup::AWS",
            "geometry": {
              "x": "24",
              "y": "24",
              "width": "56",
              "height": "56"
            },
            "additional_properties": {
              "id": "7f0e69f9-01c8-4616-b50d-4979c24a2eb0",
              "edge": false,
              "service_type": "securitygroup",
              "generic_type": "Services::Network::SecurityGroup",
              "type": "Services::Network::SecurityGroup::AWS",
              "name": " atit-sec-group-1",
              "drawable": false,
              "draggable": false,
              "interfaces": [
                {
                  "id": "bb6e1f23-0523-4d70-a5d6-ea83152862ef",
                  "name": " atit-sec-group-1",
                  "depends": false,
                  "connections": null,
                  "interface_type": "Protocols::SecurityGroup"
                },
                {
                  "id": "0c4ad48a-fc6c-47b4-9dfc-9a9cdf32ed77",
                  "name": null,
                  "depends": true,
                  "connections": [
                    {
                      "id": "83b2f2ad-eee1-40bb-87d1-c71b559fd111",
                      "interface_id": "0c4ad48a-fc6c-47b4-9dfc-9a9cdf32ed77",
                      "remote_interface_id": "30f34d56-7767-4f6e-b3d9-e138fc5d21e5",
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
              "parent_id": "5fdc0cf0-3dac-4074-a1d8-c811d3891314",
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
                      "groups": null,
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
                      "groups": null,
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
                      "groups": null,
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
                      "groups": null,
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
              ]
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
                    "groups": null,
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
                    "groups": null,
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
                    "groups": null,
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
                    "groups": null,
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
                "id": "bb6e1f23-0523-4d70-a5d6-ea83152862ef",
                "name": " atit-sec-group-1",
                "interface_type": "Protocols::SecurityGroup",
                "depends": false,
                "connections": []
              },
              {
                "id": "0c4ad48a-fc6c-47b4-9dfc-9a9cdf32ed77",
                "interface_type": "Protocols::Vpc",
                "depends": true,
                "connections": [
                  {
                    "id": "83b2f2ad-eee1-40bb-87d1-c71b559fd111",
                    "interface_id": "0c4ad48a-fc6c-47b4-9dfc-9a9cdf32ed77",
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
            "id": "fea21634-5f39-4397-aa58-dae84d51fcf4",
            "name": "ap-southeast-1b",
            "type": "Services::Network::AvailabilityZone",
            "geometry": {
              "x": "18",
              "y": "204",
              "width": "220",
              "height": "138"
            },
            "additional_properties": {
              "id": "fea21634-5f39-4397-aa58-dae84d51fcf4",
              "edge": false,
              "service_type": "availabilityzone",
              "generic_type": "Services::Network::AvailabilityZone",
              "type": "Services::Network::AvailabilityZone",
              "name": "ap-southeast-1b",
              "drawable": true,
              "draggable": true,
              "interfaces": [
                {
                  "id": "19888504-d02a-43db-ba5c-1245c50c87a7",
                  "name": "ap-southeast-1b",
                  "depends": false,
                  "connections": null,
                  "interface_type": "Protocols::AvailabilityZone"
                },
                {
                  "id": "986f0e05-f2f4-45b8-a1d8-e293f9cb1eac",
                  "name": null,
                  "depends": true,
                  "connections": [
                    {
                      "id": "71824378-8598-44b1-bd3b-ee695a76ecd5",
                      "interface_id": "986f0e05-f2f4-45b8-a1d8-e293f9cb1eac",
                      "remote_interface_id": "30f34d56-7767-4f6e-b3d9-e138fc5d21e5",
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
              "depends": null,
              "parent_id": "5fdc0cf0-3dac-4074-a1d8-c811d3891314",
              "properties": [
                {
                  "name": "code",
                  "value": "ap-southeast-1b",
                  "form_options": {
                    "type": "select",
                    "options": [
                      "ap-southeast-1a",
                      "ap-southeast-1b"
                    ]
                  },
                  "title": "Availability Zone Name"
                }
              ]
            },
            "properties": [
              {
                "name": "code",
                "value": "ap-southeast-1b",
                "form_options": {
                  "type": "select",
                  "options": [
                    "ap-southeast-1a",
                    "ap-southeast-1b"
                  ]
                },
                "title": "Availability Zone Name"
              }
            ],
            "interfaces": [
              {
                "id": "19888504-d02a-43db-ba5c-1245c50c87a7",
                "name": "ap-southeast-1b",
                "interface_type": "Protocols::AvailabilityZone",
                "depends": false,
                "connections": []
              },
              {
                "id": "986f0e05-f2f4-45b8-a1d8-e293f9cb1eac",
                "interface_type": "Protocols::Vpc",
                "depends": true,
                "connections": [
                  {
                    "id": "71824378-8598-44b1-bd3b-ee695a76ecd5",
                    "interface_id": "986f0e05-f2f4-45b8-a1d8-e293f9cb1eac",
                    "remote_interface_id": "30f34d56-7767-4f6e-b3d9-e138fc5d21e5",
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
            "id": "06a46846-628d-4274-9dc9-83450aa8672f",
            "name": "ap-southeast-1a",
            "type": "Services::Network::AvailabilityZone",
            "geometry": {
              "x": "18",
              "y": "22",
              "width": "220",
              "height": "138"
            },
            "additional_properties": {
              "id": "06a46846-628d-4274-9dc9-83450aa8672f",
              "edge": false,
              "service_type": "availabilityzone",
              "generic_type": "Services::Network::AvailabilityZone",
              "type": "Services::Network::AvailabilityZone",
              "name": "ap-southeast-1a",
              "drawable": true,
              "draggable": true,
              "interfaces": [
                {
                  "id": "21590205-9674-4209-a177-109f31746389",
                  "name": "ap-southeast-1a",
                  "depends": false,
                  "connections": null,
                  "interface_type": "Protocols::AvailabilityZone"
                },
                {
                  "id": "89e63063-bfa4-4d9e-b7b7-914a0a8e5d38",
                  "name": null,
                  "depends": true,
                  "connections": [
                    {
                      "id": "db6f6ea2-120a-4a4c-8560-e4038bd07d25",
                      "interface_id": "89e63063-bfa4-4d9e-b7b7-914a0a8e5d38",
                      "remote_interface_id": "30f34d56-7767-4f6e-b3d9-e138fc5d21e5",
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
              "depends": null,
              "parent_id": "5fdc0cf0-3dac-4074-a1d8-c811d3891314",
              "properties": [
                {
                  "name": "code",
                  "value": "ap-southeast-1a",
                  "form_options": {
                    "type": "select",
                    "options": [
                      "ap-southeast-1a",
                      "ap-southeast-1b"
                    ]
                  },
                  "title": "Availability Zone Name"
                }
              ]
            },
            "properties": [
              {
                "name": "code",
                "value": "ap-southeast-1a",
                "form_options": {
                  "type": "select",
                  "options": [
                    "ap-southeast-1a",
                    "ap-southeast-1b"
                  ]
                },
                "title": "Availability Zone Name"
              }
            ],
            "interfaces": [
              {
                "id": "21590205-9674-4209-a177-109f31746389",
                "name": "ap-southeast-1a",
                "interface_type": "Protocols::AvailabilityZone",
                "depends": false,
                "connections": []
              },
              {
                "id": "89e63063-bfa4-4d9e-b7b7-914a0a8e5d38",
                "interface_type": "Protocols::Vpc",
                "depends": true,
                "connections": [
                  {
                    "id": "db6f6ea2-120a-4a4c-8560-e4038bd07d25",
                    "interface_id": "89e63063-bfa4-4d9e-b7b7-914a0a8e5d38",
                    "remote_interface_id": "30f34d56-7767-4f6e-b3d9-e138fc5d21e5",
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
            "id": "3f272f45-fc84-415d-bdec-e57ca439475d",
            "name": "RTB-V20-$$function##",
            "type": "Services::Network::RouteTable::AWS",
            "geometry": {
              "x": "528",
              "y": "125",
              "width": "56",
              "height": "56"
            },
            "additional_properties": {
              "id": "3f272f45-fc84-415d-bdec-e57ca439475d",
              "edge": false,
              "service_type": "routetable",
              "generic_type": "Services::Network::RouteTable",
              "type": "Services::Network::RouteTable::AWS",
              "name": "RTB-V20-$$function##",
              "drawable": true,
              "draggable": true,
              "interfaces": [
                {
                  "id": "8494b2d5-493c-4e64-8742-e51319710e0a",
                  "name": "atit-public-route",
                  "depends": false,
                  "connections": null,
                  "interface_type": "Protocols::RouteTable"
                },
                {
                  "id": "f1db5c0a-548f-4176-ac53-257d96b2c4d4",
                  "name": null,
                  "depends": true,
                  "connections": [
                    {
                      "id": "b9a7790f-4acb-4273-8a13-feeb3858c9fd",
                      "interface_id": "f1db5c0a-548f-4176-ac53-257d96b2c4d4",
                      "remote_interface_id": "30f34d56-7767-4f6e-b3d9-e138fc5d21e5",
                      "internal": false,
                      "type": "implicit"
                    }
                  ],
                  "interface_type": "Protocols::Vpc"
                },
                {
                  "id": "b87bd98d-3b3a-4b9c-9219-aaf90985b431",
                  "name": null,
                  "depends": true,
                  "connections": [
                    {
                      "id": "cc915f23-76a9-4c9a-a636-bb39de8b36bf",
                      "interface_id": "b87bd98d-3b3a-4b9c-9219-aaf90985b431",
                      "remote_interface_id": "bd4b12c0-cd4d-4551-8007-cb8af3c12f85",
                      "internal": false
                    }
                  ],
                  "interface_type": "Protocols::Subnet"
                }
              ],
              "vpc_id": "b4d269a4-49c5-42bf-b7c1-2818118fe6a7",
              "primary_key": "",
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
              "depends": null,
              "parent_id": "5fdc0cf0-3dac-4074-a1d8-c811d3891314",
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
              ]
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
                "id": "8494b2d5-493c-4e64-8742-e51319710e0a",
                "name": "atit-public-route",
                "interface_type": "Protocols::RouteTable",
                "depends": false,
                "connections": []
              },
              {
                "id": "f1db5c0a-548f-4176-ac53-257d96b2c4d4",
                "interface_type": "Protocols::Vpc",
                "depends": true,
                "connections": [
                  {
                    "id": "b9a7790f-4acb-4273-8a13-feeb3858c9fd",
                    "interface_id": "f1db5c0a-548f-4176-ac53-257d96b2c4d4",
                    "remote_interface_id": "30f34d56-7767-4f6e-b3d9-e138fc5d21e5",
                    "internal": false
                  }
                ]
              },
              {
                "id": "b87bd98d-3b3a-4b9c-9219-aaf90985b431",
                "interface_type": "Protocols::Subnet",
                "depends": true,
                "connections": [
                  {
                    "id": "cc915f23-76a9-4c9a-a636-bb39de8b36bf",
                    "interface_id": "b87bd98d-3b3a-4b9c-9219-aaf90985b431",
                    "remote_interface_id": "bd4b12c0-cd4d-4551-8007-cb8af3c12f85",
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
            "id": "9224d9fb-f827-476b-8721-554443acb138",
            "name": "main",
            "type": "Services::Network::RouteTable::AWS",
            "geometry": {
              "x": "528",
              "y": "25",
              "width": "56",
              "height": "56"
            },
            "additional_properties": {
              "id": "9224d9fb-f827-476b-8721-554443acb138",
              "edge": false,
              "service_type": "routetable",
              "generic_type": "Services::Network::RouteTable",
              "type": "Services::Network::RouteTable::AWS",
              "name": "main",
              "drawable": true,
              "draggable": false,
              "interfaces": [
                {
                  "id": "e112e125-e85c-41f5-8e5d-472ba6b53cb0",
                  "name": "main",
                  "depends": false,
                  "connections": null,
                  "interface_type": "Protocols::RouteTable"
                },
                {
                  "id": "c3e2fa6a-5048-4fc7-9aab-66debb0b32b7",
                  "name": null,
                  "depends": true,
                  "connections": [
                    {
                      "id": "a114dfe1-9e63-41b6-826f-2653537af26f",
                      "interface_id": "c3e2fa6a-5048-4fc7-9aab-66debb0b32b7",
                      "remote_interface_id": "30f34d56-7767-4f6e-b3d9-e138fc5d21e5",
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
              "depends": null,
              "parent_id": "5fdc0cf0-3dac-4074-a1d8-c811d3891314",
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
              ]
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
                "id": "e112e125-e85c-41f5-8e5d-472ba6b53cb0",
                "name": "main",
                "interface_type": "Protocols::RouteTable",
                "depends": false,
                "connections": []
              },
              {
                "id": "c3e2fa6a-5048-4fc7-9aab-66debb0b32b7",
                "interface_type": "Protocols::Vpc",
                "depends": true,
                "connections": [
                  {
                    "id": "a114dfe1-9e63-41b6-826f-2653537af26f",
                    "interface_id": "c3e2fa6a-5048-4fc7-9aab-66debb0b32b7",
                    "remote_interface_id": "30f34d56-7767-4f6e-b3d9-e138fc5d21e5",
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
            "id": "cf0a8f45-9e16-4aed-b439-5cec67901c51",
            "name": "internet",
            "type": "Services::Network::InternetGateway::AWS",
            "geometry": {
              "x": "-28",
              "y": "100",
              "width": "56",
              "height": "56"
            },
            "additional_properties": {
              "id": "cf0a8f45-9e16-4aed-b439-5cec67901c51",
              "edge": false,
              "service_type": "internetgateway",
              "generic_type": "Services::Network::InternetGateway",
              "type": "Services::Network::InternetGateway::AWS",
              "name": "internet",
              "drawable": true,
              "draggable": false,
              "interfaces": [
                {
                  "id": "98f89193-40bd-4af6-8be0-42dddd62850b",
                  "name": "internet",
                  "depends": false,
                  "connections": null,
                  "interface_type": "Protocols::InternetGateway"
                },
                {
                  "id": "5b819b95-1a91-4a01-80dc-2abcf1db5acd",
                  "name": null,
                  "depends": true,
                  "connections": [
                    {
                      "id": "52382d0f-8ca5-44fb-a1f1-2da6a1eb76fe",
                      "interface_id": "5b819b95-1a91-4a01-80dc-2abcf1db5acd",
                      "remote_interface_id": "30f34d56-7767-4f6e-b3d9-e138fc5d21e5",
                      "internal": false,
                      "type": "implicit"
                    }
                  ],
                  "interface_type": "Protocols::Vpc"
                },
                {
                  "id": "3816e210-4969-4750-9692-73e7ced6f72e",
                  "name": null,
                  "depends": true,
                  "connections": [
                    {
                      "id": "57d0e923-80d2-48d6-8c7e-20a21b8fd931",
                      "interface_id": "3816e210-4969-4750-9692-73e7ced6f72e",
                      "remote_interface_id": "e112e125-e85c-41f5-8e5d-472ba6b53cb0",
                      "internal": false
                    },
                    {
                      "id": "625200f4-5648-475f-aa1d-17db02bf6082",
                      "interface_id": "3816e210-4969-4750-9692-73e7ced6f72e",
                      "remote_interface_id": "8494b2d5-493c-4e64-8742-e51319710e0a",
                      "internal": false
                    }
                  ],
                  "interface_type": "Protocols::RouteTable"
                }
              ],
              "vpc_id": "b4d269a4-49c5-42bf-b7c1-2818118fe6a7",
              "primary_key": "",
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
              "depends": null,
              "parent_id": "5fdc0cf0-3dac-4074-a1d8-c811d3891314",
              "properties": [
                {
                  "name": "internet_gateway_id",
                  "value": "igw-3826655d",
                  "form_options": {
                    "type": "hidden"
                  },
                  "title": "Internet Gateway Id"
                }
              ]
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
                "id": "98f89193-40bd-4af6-8be0-42dddd62850b",
                "name": "internet",
                "interface_type": "Protocols::InternetGateway",
                "depends": false,
                "connections": []
              },
              {
                "id": "5b819b95-1a91-4a01-80dc-2abcf1db5acd",
                "interface_type": "Protocols::Vpc",
                "depends": true,
                "connections": [
                  {
                    "id": "52382d0f-8ca5-44fb-a1f1-2da6a1eb76fe",
                    "interface_id": "5b819b95-1a91-4a01-80dc-2abcf1db5acd",
                    "remote_interface_id": "30f34d56-7767-4f6e-b3d9-e138fc5d21e5",
                    "internal": false
                  }
                ]
              },
              {
                "id": "3816e210-4969-4750-9692-73e7ced6f72e",
                "interface_type": "Protocols::RouteTable",
                "depends": true,
                "connections": [
                  {
                    "id": "57d0e923-80d2-48d6-8c7e-20a21b8fd931",
                    "interface_id": "3816e210-4969-4750-9692-73e7ced6f72e",
                    "remote_interface_id": "e112e125-e85c-41f5-8e5d-472ba6b53cb0",
                    "internal": false
                  },
                  {
                    "id": "625200f4-5648-475f-aa1d-17db02bf6082",
                    "interface_id": "3816e210-4969-4750-9692-73e7ced6f72e",
                    "remote_interface_id": "8494b2d5-493c-4e64-8742-e51319710e0a",
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
            "id": "56735a85-b666-44b6-b930-736ec48da5fd",
            "name": "atit-subnet2",
            "type": "Services::Network::Subnet::AWS",
            "geometry": {
              "x": "18",
              "y": "22",
              "width": "182",
              "height": "96"
            },
            "container": true,
            "additional_properties": {
              "id": "56735a85-b666-44b6-b930-736ec48da5fd",
              "edge": false,
              "service_type": "subnet",
              "generic_type": "Services::Network::Subnet",
              "type": "Services::Network::Subnet::AWS",
              "name": "atit-subnet2",
              "drawable": true,
              "draggable": true,
              "interfaces": [
                {
                  "id": "12eeb24c-b2d3-44ae-a484-87fc656a528d",
                  "name": "atit-subnet2",
                  "depends": false,
                  "connections": null,
                  "interface_type": "Protocols::Subnet"
                },
                {
                  "id": "e8ee56d8-684d-4603-be0e-1f7a8819270b",
                  "name": null,
                  "depends": true,
                  "connections": [
                    {
                      "id": "88615f14-7358-4cc8-90d8-638ad19d0ca9",
                      "interface_id": "e8ee56d8-684d-4603-be0e-1f7a8819270b",
                      "remote_interface_id": "30f34d56-7767-4f6e-b3d9-e138fc5d21e5",
                      "internal": false
                    }
                  ],
                  "interface_type": "Protocols::Vpc"
                },
                {
                  "id": "11806fe1-36d7-427b-ad1f-62e67ad1ab17",
                  "name": null,
                  "depends": true,
                  "connections": [
                    {
                      "id": "9410e8b1-bccf-4484-8285-84a09373a714",
                      "interface_id": "11806fe1-36d7-427b-ad1f-62e67ad1ab17",
                      "remote_interface_id": "19888504-d02a-43db-ba5c-1245c50c87a7",
                      "internal": false,
                      "type": "implicit"
                    }
                  ],
                  "interface_type": "Protocols::AvailabilityZone"
                }
              ],
              "vpc_id": "b4d269a4-49c5-42bf-b7c1-2818118fe6a7",
              "primary_key": "",
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
              "parent_id": "fea21634-5f39-4397-aa58-dae84d51fcf4",
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
              "existing_subnet": ""
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
                "id": "12eeb24c-b2d3-44ae-a484-87fc656a528d",
                "name": "atit-subnet2",
                "interface_type": "Protocols::Subnet",
                "depends": false,
                "connections": []
              },
              {
                "id": "e8ee56d8-684d-4603-be0e-1f7a8819270b",
                "interface_type": "Protocols::Vpc",
                "depends": true,
                "connections": [
                  {
                    "id": "88615f14-7358-4cc8-90d8-638ad19d0ca9",
                    "interface_id": "e8ee56d8-684d-4603-be0e-1f7a8819270b",
                    "remote_interface_id": "30f34d56-7767-4f6e-b3d9-e138fc5d21e5",
                    "internal": false
                  }
                ]
              },
              {
                "id": "11806fe1-36d7-427b-ad1f-62e67ad1ab17",
                "interface_type": "Protocols::AvailabilityZone",
                "depends": true,
                "connections": [
                  {
                    "id": "9410e8b1-bccf-4484-8285-84a09373a714",
                    "interface_id": "11806fe1-36d7-427b-ad1f-62e67ad1ab17",
                    "remote_interface_id": "19888504-d02a-43db-ba5c-1245c50c87a7",
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
            "id": "172032cb-6c61-459c-9367-47b8f6acdc43",
            "name": "atit-subnet1",
            "type": "Services::Network::Subnet::AWS",
            "geometry": {
              "x": "18",
              "y": "22",
              "width": "182",
              "height": "96"
            },
            "container": true,
            "additional_properties": {
              "id": "172032cb-6c61-459c-9367-47b8f6acdc43",
              "edge": false,
              "service_type": "subnet",
              "generic_type": "Services::Network::Subnet",
              "type": "Services::Network::Subnet::AWS",
              "name": "atit-subnet1",
              "drawable": true,
              "draggable": true,
              "interfaces": [
                {
                  "id": "bd4b12c0-cd4d-4551-8007-cb8af3c12f85",
                  "name": "atit-subnet1",
                  "depends": false,
                  "connections": null,
                  "interface_type": "Protocols::Subnet"
                },
                {
                  "id": "64dd2779-837c-4245-9c1c-a5db1d04a4b0",
                  "name": null,
                  "depends": true,
                  "connections": [
                    {
                      "id": "7b4f919d-60ee-4983-9a6d-e58e1c23e679",
                      "interface_id": "64dd2779-837c-4245-9c1c-a5db1d04a4b0",
                      "remote_interface_id": "30f34d56-7767-4f6e-b3d9-e138fc5d21e5",
                      "internal": false
                    }
                  ],
                  "interface_type": "Protocols::Vpc"
                },
                {
                  "id": "cbdd0799-18c5-47b9-8aee-7276604896e1",
                  "name": null,
                  "depends": true,
                  "connections": [
                    {
                      "id": "b519da37-3814-4ae7-90d7-4e7f8338a08e",
                      "interface_id": "cbdd0799-18c5-47b9-8aee-7276604896e1",
                      "remote_interface_id": "21590205-9674-4209-a177-109f31746389",
                      "internal": false,
                      "type": "implicit"
                    }
                  ],
                  "interface_type": "Protocols::AvailabilityZone"
                }
              ],
              "vpc_id": "b4d269a4-49c5-42bf-b7c1-2818118fe6a7",
              "primary_key": "",
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
              "parent_id": "06a46846-628d-4274-9dc9-83450aa8672f",
              "properties": [
                {
                  "name": "cidr_block",
                  "value": "10.0.32.0/19",
                  "form_options": {
                    "type": "text"
                  },
                  "title": "CIDR Block"
                }
              ],
              "lastAssignedIP": "10.0.32.0",
              "existing_subnet": ""
            },
            "properties": [
              {
                "name": "cidr_block",
                "value": "10.0.32.0/19",
                "form_options": {
                  "type": "text"
                },
                "title": "CIDR Block"
              }
            ],
            "interfaces": [
              {
                "id": "bd4b12c0-cd4d-4551-8007-cb8af3c12f85",
                "name": "atit-subnet1",
                "interface_type": "Protocols::Subnet",
                "depends": false,
                "connections": []
              },
              {
                "id": "64dd2779-837c-4245-9c1c-a5db1d04a4b0",
                "interface_type": "Protocols::Vpc",
                "depends": true,
                "connections": [
                  {
                    "id": "7b4f919d-60ee-4983-9a6d-e58e1c23e679",
                    "interface_id": "64dd2779-837c-4245-9c1c-a5db1d04a4b0",
                    "remote_interface_id": "30f34d56-7767-4f6e-b3d9-e138fc5d21e5",
                    "internal": false
                  }
                ]
              },
              {
                "id": "cbdd0799-18c5-47b9-8aee-7276604896e1",
                "interface_type": "Protocols::AvailabilityZone",
                "depends": true,
                "connections": [
                  {
                    "id": "b519da37-3814-4ae7-90d7-4e7f8338a08e",
                    "interface_id": "cbdd0799-18c5-47b9-8aee-7276604896e1",
                    "remote_interface_id": "21590205-9674-4209-a177-109f31746389",
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
            "id": "41795577-8a70-4b52-ae1b-6227b0abd9b1",
            "name": "EC2-$$servername-$$function##-V20",
            "type": "Services::Compute::Server::AWS",
            "geometry": {
              "x": "20",
              "y": "20",
              "width": "56",
              "height": "56"
            },
            "additional_properties": {
              "id": "41795577-8a70-4b52-ae1b-6227b0abd9b1",
              "edge": false,
              "service_type": "server",
              "generic_type": "Services::Compute::Server",
              "type": "Services::Compute::Server::AWS",
              "name": "EC2-$$servername-$$function##-V20",
              "drawable": true,
              "draggable": true,
              "interfaces": [
                {
                  "id": "8a382bc3-aeb8-4ea8-9683-08d9082400f6",
                  "name": "atit-DB",
                  "depends": false,
                  "connections": null,
                  "interface_type": "Protocols::Server"
                },
                {
                  "id": "f7649d79-d492-4f41-828d-dea8b898a73e",
                  "name": null,
                  "depends": true,
                  "connections": [
                    {
                      "id": "a8ec8923-9dfb-42fe-8d9c-a9c2e7d663bd",
                      "interface_id": "f7649d79-d492-4f41-828d-dea8b898a73e",
                      "remote_interface_id": "30f34d56-7767-4f6e-b3d9-e138fc5d21e5",
                      "internal": false
                    }
                  ],
                  "interface_type": "Protocols::Vpc"
                },
                {
                  "id": "c2896375-18f1-41fc-bf26-8f1c1142d04e",
                  "name": null,
                  "depends": true,
                  "connections": [
                    {
                      "id": "59d50f54-4220-4901-a63d-1ea548a2062f",
                      "interface_id": "c2896375-18f1-41fc-bf26-8f1c1142d04e",
                      "remote_interface_id": "35987ec9-22a8-485b-bda1-e7a561d8f097",
                      "internal": false
                    }
                  ],
                  "interface_type": "Protocols::SecurityGroup"
                },
                {
                  "id": "92aca2f7-822f-44a8-888a-f8689922c4a2",
                  "name": null,
                  "depends": true,
                  "connections": [
                    {
                      "id": "f20f9762-e240-484f-91e6-7ce5297eb206",
                      "interface_id": "92aca2f7-822f-44a8-888a-f8689922c4a2",
                      "remote_interface_id": "12eeb24c-b2d3-44ae-a484-87fc656a528d",
                      "internal": false,
                      "type": "implicit"
                    }
                  ],
                  "interface_type": "Protocols::Subnet"
                },
                {
                  "id": "1a89a54a-323b-4f4b-967a-5865942c0b9d",
                  "name": null,
                  "depends": true,
                  "connections": [
                    {
                      "id": "38a005fd-620e-40f1-a0ac-cf0567ac93cc",
                      "interface_id": "1a89a54a-323b-4f4b-967a-5865942c0b9d",
                      "remote_interface_id": "348c5666-8743-4036-8219-ce9ac086ec13",
                      "internal": false,
                      "type": "implicit"
                    }
                  ],
                  "interface_type": "Protocols::Disk"
                }
              ],
              "vpc_id": "b4d269a4-49c5-42bf-b7c1-2818118fe6a7",
              "primary_key": "",
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
              "parent_id": "56735a85-b666-44b6-b930-736ec48da5fd",
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
                        "t2.large",
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
                      "t2.large",
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
                  "value": "",
                  "form_options": {
                    "type": "select",
                    "options": [
                      "asdsad"
                    ],
                    "data": [
                      "asdsad"
                    ],
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
              ]
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
                      "t2.large",
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
                    "t2.large",
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
                "value": "",
                "form_options": {
                  "type": "select",
                  "options": [
                    "asdsad"
                  ],
                  "data": [
                    "asdsad"
                  ],
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
                "id": "8a382bc3-aeb8-4ea8-9683-08d9082400f6",
                "name": "atit-DB",
                "interface_type": "Protocols::Server",
                "depends": false,
                "connections": []
              },
              {
                "id": "f7649d79-d492-4f41-828d-dea8b898a73e",
                "interface_type": "Protocols::Vpc",
                "depends": true,
                "connections": [
                  {
                    "id": "a8ec8923-9dfb-42fe-8d9c-a9c2e7d663bd",
                    "interface_id": "f7649d79-d492-4f41-828d-dea8b898a73e",
                    "remote_interface_id": "30f34d56-7767-4f6e-b3d9-e138fc5d21e5",
                    "internal": false
                  }
                ]
              },
              {
                "id": "c2896375-18f1-41fc-bf26-8f1c1142d04e",
                "interface_type": "Protocols::SecurityGroup",
                "depends": true,
                "connections": [
                  {
                    "id": "59d50f54-4220-4901-a63d-1ea548a2062f",
                    "interface_id": "c2896375-18f1-41fc-bf26-8f1c1142d04e",
                    "remote_interface_id": "35987ec9-22a8-485b-bda1-e7a561d8f097",
                    "internal": false
                  }
                ]
              },
              {
                "id": "92aca2f7-822f-44a8-888a-f8689922c4a2",
                "interface_type": "Protocols::Subnet",
                "depends": true,
                "connections": [
                  {
                    "id": "f20f9762-e240-484f-91e6-7ce5297eb206",
                    "interface_id": "92aca2f7-822f-44a8-888a-f8689922c4a2",
                    "remote_interface_id": "12eeb24c-b2d3-44ae-a484-87fc656a528d",
                    "internal": false
                  }
                ]
              },
              {
                "id": "1a89a54a-323b-4f4b-967a-5865942c0b9d",
                "interface_type": "Protocols::Disk",
                "depends": true,
                "connections": [
                  {
                    "id": "38a005fd-620e-40f1-a0ac-cf0567ac93cc",
                    "interface_id": "1a89a54a-323b-4f4b-967a-5865942c0b9d",
                    "remote_interface_id": "348c5666-8743-4036-8219-ce9ac086ec13",
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
            "id": "09629d00-6bad-453b-b24a-d170d1d46802",
            "name": "EC2-$$servername-$$function##-V20",
            "type": "Services::Compute::Server::AWS",
            "geometry": {
              "x": "20",
              "y": "20",
              "width": "56",
              "height": "56"
            },
            "additional_properties": {
              "id": "09629d00-6bad-453b-b24a-d170d1d46802",
              "edge": false,
              "service_type": "server",
              "generic_type": "Services::Compute::Server",
              "type": "Services::Compute::Server::AWS",
              "name": "EC2-$$servername-$$function##-V20",
              "drawable": true,
              "draggable": true,
              "interfaces": [
                {
                  "id": "037e2699-5669-4def-883d-676cb764cadd",
                  "name": "atit-AWS-Linux",
                  "depends": false,
                  "connections": null,
                  "interface_type": "Protocols::Server"
                },
                {
                  "id": "c660a9f4-8515-4e5a-b7b5-770036422d3e",
                  "name": null,
                  "depends": true,
                  "connections": [
                    {
                      "id": "82b819cb-d800-4526-9757-1b7783f1acfb",
                      "interface_id": "c660a9f4-8515-4e5a-b7b5-770036422d3e",
                      "remote_interface_id": "30f34d56-7767-4f6e-b3d9-e138fc5d21e5",
                      "internal": false
                    }
                  ],
                  "interface_type": "Protocols::Vpc"
                },
                {
                  "id": "ce6dd11f-5251-49b0-9f19-ef173ba3e81c",
                  "name": null,
                  "depends": true,
                  "connections": [
                    {
                      "id": "dbdb046a-8962-434c-a935-f42f4c5b97d4",
                      "interface_id": "ce6dd11f-5251-49b0-9f19-ef173ba3e81c",
                      "remote_interface_id": "bb6e1f23-0523-4d70-a5d6-ea83152862ef",
                      "internal": false
                    }
                  ],
                  "interface_type": "Protocols::SecurityGroup"
                },
                {
                  "id": "1b464685-7049-4ba8-865e-e5dbe93da6f0",
                  "name": null,
                  "depends": true,
                  "connections": [
                    {
                      "id": "6c0d3e9f-3dea-47fc-8fbc-c118f3f2630d",
                      "interface_id": "1b464685-7049-4ba8-865e-e5dbe93da6f0",
                      "remote_interface_id": "bd4b12c0-cd4d-4551-8007-cb8af3c12f85",
                      "internal": false,
                      "type": "implicit"
                    }
                  ],
                  "interface_type": "Protocols::Subnet"
                },
                {
                  "id": "ecbaf726-cc27-4fe7-811c-1479573c2c34",
                  "name": null,
                  "depends": true,
                  "connections": [
                    {
                      "id": "9893cf5b-3063-41f0-a5d8-0028b03a733c",
                      "interface_id": "ecbaf726-cc27-4fe7-811c-1479573c2c34",
                      "remote_interface_id": "a4f3846d-6e45-453c-be9b-e24d55b695f4",
                      "internal": false,
                      "type": "implicit"
                    }
                  ],
                  "interface_type": "Protocols::Disk"
                }
              ],
              "vpc_id": "b4d269a4-49c5-42bf-b7c1-2818118fe6a7",
              "primary_key": "",
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
              "parent_id": "172032cb-6c61-459c-9367-47b8f6acdc43",
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
                        "t2.large",
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
                      "t2.large",
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
                  "value": "",
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
                  "value": "",
                  "form_options": {
                    "type": "select",
                    "options": [
                      "asdsad"
                    ],
                    "data": [
                      "asdsad"
                    ],
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
              ]
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
                      "t2.large",
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
                    "t2.large",
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
                "value": "",
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
                "value": "",
                "form_options": {
                  "type": "select",
                  "options": [
                    "asdsad"
                  ],
                  "data": [
                    "asdsad"
                  ],
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
                "id": "037e2699-5669-4def-883d-676cb764cadd",
                "name": "atit-AWS-Linux",
                "interface_type": "Protocols::Server",
                "depends": false,
                "connections": []
              },
              {
                "id": "c660a9f4-8515-4e5a-b7b5-770036422d3e",
                "interface_type": "Protocols::Vpc",
                "depends": true,
                "connections": [
                  {
                    "id": "82b819cb-d800-4526-9757-1b7783f1acfb",
                    "interface_id": "c660a9f4-8515-4e5a-b7b5-770036422d3e",
                    "remote_interface_id": "30f34d56-7767-4f6e-b3d9-e138fc5d21e5",
                    "internal": false
                  }
                ]
              },
              {
                "id": "ce6dd11f-5251-49b0-9f19-ef173ba3e81c",
                "interface_type": "Protocols::SecurityGroup",
                "depends": true,
                "connections": [
                  {
                    "id": "dbdb046a-8962-434c-a935-f42f4c5b97d4",
                    "interface_id": "ce6dd11f-5251-49b0-9f19-ef173ba3e81c",
                    "remote_interface_id": "bb6e1f23-0523-4d70-a5d6-ea83152862ef",
                    "internal": false
                  }
                ]
              },
              {
                "id": "1b464685-7049-4ba8-865e-e5dbe93da6f0",
                "interface_type": "Protocols::Subnet",
                "depends": true,
                "connections": [
                  {
                    "id": "6c0d3e9f-3dea-47fc-8fbc-c118f3f2630d",
                    "interface_id": "1b464685-7049-4ba8-865e-e5dbe93da6f0",
                    "remote_interface_id": "bd4b12c0-cd4d-4551-8007-cb8af3c12f85",
                    "internal": false
                  }
                ]
              },
              {
                "id": "ecbaf726-cc27-4fe7-811c-1479573c2c34",
                "interface_type": "Protocols::Disk",
                "depends": true,
                "connections": [
                  {
                    "id": "9893cf5b-3063-41f0-a5d8-0028b03a733c",
                    "interface_id": "ecbaf726-cc27-4fe7-811c-1479573c2c34",
                    "remote_interface_id": "a4f3846d-6e45-453c-be9b-e24d55b695f4",
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
            "id": "a19ff55e-dc2f-4d1a-a93c-757c8cd1be89",
            "name": "VOL-$$serviceowner-$$function##-V20-",
            "type": "Services::Compute::Server::Volume::AWS",
            "geometry": {
              "x": "10",
              "y": "18",
              "width": "56",
              "height": "56"
            },
            "internal": true,
            "additional_properties": {
              "id": "a19ff55e-dc2f-4d1a-a93c-757c8cd1be89",
              "numbered": true,
              "edge": false,
              "service_type": "volume",
              "generic_type": "Services::Compute::Server::Volume",
              "type": "Services::Compute::Server::Volume::AWS",
              "name": "VOL-$$serviceowner-$$function##-V20-",
              "internal": true,
              "drawable": false,
              "draggable": true,
              "interfaces": [
                {
                  "id": "a4f3846d-6e45-453c-be9b-e24d55b695f4",
                  "name": "vol-e714693a",
                  "depends": false,
                  "connections": null,
                  "interface_type": "Protocols::Disk"
                },
                {
                  "id": "aa267f2c-7bb3-48e4-bbb3-7b0eebc87dcb",
                  "name": null,
                  "depends": true,
                  "connections": [
                    {
                      "id": "8bf0cd37-aefc-4f56-b0a2-6ad065ab4245",
                      "interface_id": "aa267f2c-7bb3-48e4-bbb3-7b0eebc87dcb",
                      "remote_interface_id": "30f34d56-7767-4f6e-b3d9-e138fc5d21e5",
                      "internal": false
                    }
                  ],
                  "interface_type": "Protocols::Vpc"
                },
                {
                  "id": "a94fd183-410b-4ef7-bda5-c48b0fe965f5",
                  "name": null,
                  "depends": true,
                  "connections": [
                    {
                      "id": "58a40faf-966d-405c-8396-dcc1cb660827",
                      "interface_id": "a94fd183-410b-4ef7-bda5-c48b0fe965f5",
                      "remote_interface_id": "21590205-9674-4209-a177-109f31746389",
                      "internal": false,
                      "type": "implicit"
                    }
                  ],
                  "interface_type": "Protocols::AvailabilityZone"
                }
              ],
              "vpc_id": "b4d269a4-49c5-42bf-b7c1-2818118fe6a7",
              "primary_key": "",
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
              "depends": null,
              "parent_id": "09629d00-6bad-453b-b24a-d170d1d46802",
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
              ]
            },
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
                "id": "a4f3846d-6e45-453c-be9b-e24d55b695f4",
                "name": "vol-e714693a",
                "interface_type": "Protocols::Disk",
                "depends": false,
                "connections": []
              },
              {
                "id": "aa267f2c-7bb3-48e4-bbb3-7b0eebc87dcb",
                "interface_type": "Protocols::Vpc",
                "depends": true,
                "connections": [
                  {
                    "id": "8bf0cd37-aefc-4f56-b0a2-6ad065ab4245",
                    "interface_id": "aa267f2c-7bb3-48e4-bbb3-7b0eebc87dcb",
                    "remote_interface_id": "30f34d56-7767-4f6e-b3d9-e138fc5d21e5",
                    "internal": false
                  }
                ]
              },
              {
                "id": "a94fd183-410b-4ef7-bda5-c48b0fe965f5",
                "interface_type": "Protocols::AvailabilityZone",
                "depends": true,
                "connections": [
                  {
                    "id": "58a40faf-966d-405c-8396-dcc1cb660827",
                    "interface_id": "a94fd183-410b-4ef7-bda5-c48b0fe965f5",
                    "remote_interface_id": "21590205-9674-4209-a177-109f31746389",
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
            "id": "e59e7b2f-946e-49e3-9e4f-de8a55beb962",
            "name": "VOL-$$serviceowner-$$function##-V20-",
            "type": "Services::Compute::Server::Volume::AWS",
            "geometry": {
              "x": "10",
              "y": "18",
              "width": "56",
              "height": "56"
            },
            "internal": true,
            "additional_properties": {
              "id": "e59e7b2f-946e-49e3-9e4f-de8a55beb962",
              "numbered": true,
              "edge": false,
              "service_type": "volume",
              "generic_type": "Services::Compute::Server::Volume",
              "type": "Services::Compute::Server::Volume::AWS",
              "name": "VOL-$$serviceowner-$$function##-V20-",
              "internal": true,
              "drawable": false,
              "draggable": true,
              "interfaces": [
                {
                  "id": "348c5666-8743-4036-8219-ce9ac086ec13",
                  "name": "vol-afbade2b",
                  "depends": false,
                  "connections": null,
                  "interface_type": "Protocols::Disk"
                },
                {
                  "id": "87929002-76a4-492b-b17b-61d1b992e4a7",
                  "name": null,
                  "depends": true,
                  "connections": [
                    {
                      "id": "a293aa8c-6207-4c7b-b804-1a3bc6e54892",
                      "interface_id": "87929002-76a4-492b-b17b-61d1b992e4a7",
                      "remote_interface_id": "30f34d56-7767-4f6e-b3d9-e138fc5d21e5",
                      "internal": false
                    }
                  ],
                  "interface_type": "Protocols::Vpc"
                },
                {
                  "id": "915545f5-bb68-4d6c-8958-a358002de8fd",
                  "name": null,
                  "depends": true,
                  "connections": [
                    {
                      "id": "2b96e25e-c39f-43ea-89bd-9604c1fb6365",
                      "interface_id": "915545f5-bb68-4d6c-8958-a358002de8fd",
                      "remote_interface_id": "19888504-d02a-43db-ba5c-1245c50c87a7",
                      "internal": false,
                      "type": "implicit"
                    }
                  ],
                  "interface_type": "Protocols::AvailabilityZone"
                }
              ],
              "vpc_id": "b4d269a4-49c5-42bf-b7c1-2818118fe6a7",
              "primary_key": "",
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
              "depends": null,
              "parent_id": "41795577-8a70-4b52-ae1b-6227b0abd9b1",
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
              ]
            },
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
                "id": "348c5666-8743-4036-8219-ce9ac086ec13",
                "name": "vol-afbade2b",
                "interface_type": "Protocols::Disk",
                "depends": false,
                "connections": []
              },
              {
                "id": "87929002-76a4-492b-b17b-61d1b992e4a7",
                "interface_type": "Protocols::Vpc",
                "depends": true,
                "connections": [
                  {
                    "id": "a293aa8c-6207-4c7b-b804-1a3bc6e54892",
                    "interface_id": "87929002-76a4-492b-b17b-61d1b992e4a7",
                    "remote_interface_id": "30f34d56-7767-4f6e-b3d9-e138fc5d21e5",
                    "internal": false
                  }
                ]
              },
              {
                "id": "915545f5-bb68-4d6c-8958-a358002de8fd",
                "interface_type": "Protocols::AvailabilityZone",
                "depends": true,
                "connections": [
                  {
                    "id": "2b96e25e-c39f-43ea-89bd-9604c1fb6365",
                    "interface_id": "915545f5-bb68-4d6c-8958-a358002de8fd",
                    "remote_interface_id": "19888504-d02a-43db-ba5c-1245c50c87a7",
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
            "id": "489d857b-155c-46c5-8495-5a6ab8e60e99",
            "name": "eni1",
            "type": "Services::Network::NetworkInterface::AWS",
            "geometry": {
              "x": "10",
              "y": "18",
              "width": "0",
              "height": "0"
            },
            "internal": true,
            "expose": true,
            "additional_properties": {
              "id": "489d857b-155c-46c5-8495-5a6ab8e60e99",
              "numbered": false,
              "edge": false,
              "service_type": "networkinterface",
              "generic_type": "Services::Network::NetworkInterface",
              "type": "Services::Network::NetworkInterface::AWS",
              "name": "eni1",
              "internal": true,
              "drawable": false,
              "draggable": true,
              "interfaces": [
                {
                  "id": "89183c1d-d336-4b98-9925-6aca0f95501a",
                  "name": "EC2-$$servername-$$function##-V20",
                  "depends": true,
                  "connections": [
                    {
                      "id": "8e2987f3-6e72-4523-904b-485f8836af2a",
                      "interface_id": "89183c1d-d336-4b98-9925-6aca0f95501a",
                      "remote_interface_id": "8a382bc3-aeb8-4ea8-9683-08d9082400f6",
                      "type": "implicit"
                    }
                  ],
                  "interface_type": "Protocols::Server"
                },
                {
                  "id": "37ecbbdd-ca1b-4ba9-82af-8d677a05f81f",
                  "name": "default",
                  "depends": true,
                  "connections": [
                    {
                      "id": "50d6761f-629b-474a-97ec-84cf981536ee",
                      "interface_id": "37ecbbdd-ca1b-4ba9-82af-8d677a05f81f",
                      "remote_interface_id": "a6a6d2e4-077c-4ad0-aa54-9af0785c941e",
                      "type": "implicit"
                    }
                  ],
                  "interface_type": "Protocols::SecurityGroup"
                },
                {
                  "id": "08e8f671-ac96-4df1-82b7-30d15a6340d7",
                  "name": "eni",
                  "depends": false,
                  "connections": null,
                  "interface_type": "Protocols::NetworkInterface"
                },
                {
                  "id": "dc236b0f-179e-4aeb-a3a2-f631dfcf4f27",
                  "name": "atit-subnet2",
                  "depends": true,
                  "connections": [
                    {
                      "id": "dc80f478-461a-42bb-97e3-cbf9a11298d5",
                      "interface_id": "dc236b0f-179e-4aeb-a3a2-f631dfcf4f27",
                      "remote_interface_id": "12eeb24c-b2d3-44ae-a484-87fc656a528d",
                      "type": "implicit"
                    }
                  ],
                  "interface_type": "Protocols::Subnet"
                },
                {
                  "id": "8888da42-1f96-4d98-874e-306ba202d0dc",
                  "name": "atit-VPC",
                  "depends": true,
                  "connections": [
                    {
                      "id": "b2159f4a-d78a-4a93-9392-e2d4ce6ff859",
                      "interface_id": "8888da42-1f96-4d98-874e-306ba202d0dc",
                      "remote_interface_id": "30f34d56-7767-4f6e-b3d9-e138fc5d21e5",
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
              ],
              "expose": true,
              "parent_id": "56735a85-b666-44b6-b930-736ec48da5fd",
              "properties": [
                {
                  "name": "private_ips",
                  "value": [
                    {
                      "primary": "true",
                      "elasticIp": "",
                      "hasEalsticIp": false
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
                  "value": "",
                  "form_options": {
                    "type": "text",
                    "required": true
                  },
                  "title": "Description"
                },
                {
                  "name": "mac_address",
                  "value": "",
                  "form_options": {
                    "type": "hidden",
                    "readonly": "readonly"
                  },
                  "title": "Mac Address"
                },
                {
                  "name": "source_dest_check",
                  "form_options": {
                    "type": "checkbox",
                    "required": false
                  },
                  "title": "Source Destination Check"
                }
              ]
            },
            "properties": [
              {
                "name": "private_ips",
                "value": [
                  {
                    "primary": "true",
                    "elasticIp": "",
                    "hasEalsticIp": false
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
                "value": "",
                "form_options": {
                  "type": "text",
                  "required": true
                },
                "title": "Description"
              },
              {
                "name": "mac_address",
                "value": "",
                "form_options": {
                  "type": "hidden",
                  "readonly": "readonly"
                },
                "title": "Mac Address"
              },
              {
                "name": "source_dest_check",
                "form_options": {
                  "type": "checkbox",
                  "required": false
                },
                "title": "Source Destination Check"
              }
            ],
            "interfaces": [
              {
                "id": "89183c1d-d336-4b98-9925-6aca0f95501a",
                "name": "EC2-$$servername-$$function##-V20",
                "interface_type": "Protocols::Server",
                "depends": true,
                "connections": [
                  {
                    "id": "8e2987f3-6e72-4523-904b-485f8836af2a",
                    "interface_id": "89183c1d-d336-4b98-9925-6aca0f95501a",
                    "remote_interface_id": "8a382bc3-aeb8-4ea8-9683-08d9082400f6",
                    "internal": false
                  }
                ]
              },
              {
                "id": "37ecbbdd-ca1b-4ba9-82af-8d677a05f81f",
                "name": "default",
                "interface_type": "Protocols::SecurityGroup",
                "depends": true,
                "connections": []
              },
              {
                "id": "08e8f671-ac96-4df1-82b7-30d15a6340d7",
                "name": "eni",
                "interface_type": "Protocols::NetworkInterface",
                "depends": false,
                "connections": []
              },
              {
                "id": "dc236b0f-179e-4aeb-a3a2-f631dfcf4f27",
                "name": "atit-subnet2",
                "interface_type": "Protocols::Subnet",
                "depends": true,
                "connections": [
                  {
                    "id": "dc80f478-461a-42bb-97e3-cbf9a11298d5",
                    "interface_id": "dc236b0f-179e-4aeb-a3a2-f631dfcf4f27",
                    "remote_interface_id": "12eeb24c-b2d3-44ae-a484-87fc656a528d",
                    "internal": false
                  }
                ]
              },
              {
                "id": "8888da42-1f96-4d98-874e-306ba202d0dc",
                "name": "atit-VPC",
                "interface_type": "Protocols::Vpc",
                "depends": true,
                "connections": [
                  {
                    "id": "b2159f4a-d78a-4a93-9392-e2d4ce6ff859",
                    "interface_id": "8888da42-1f96-4d98-874e-306ba202d0dc",
                    "remote_interface_id": "30f34d56-7767-4f6e-b3d9-e138fc5d21e5",
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
            "id": "96bb30c1-8aa7-4515-964b-b974030e7c62",
            "name": "eni1",
            "type": "Services::Network::NetworkInterface::AWS",
            "geometry": {
              "x": "10",
              "y": "18",
              "width": "0",
              "height": "0"
            },
            "internal": true,
            "expose": true,
            "additional_properties": {
              "id": "96bb30c1-8aa7-4515-964b-b974030e7c62",
              "numbered": false,
              "edge": false,
              "service_type": "networkinterface",
              "generic_type": "Services::Network::NetworkInterface",
              "type": "Services::Network::NetworkInterface::AWS",
              "name": "eni1",
              "internal": true,
              "drawable": false,
              "draggable": true,
              "interfaces": [
                {
                  "id": "d6468d70-2c60-46cc-9a5e-fdaedfe8b6bf",
                  "name": "EC2-$$servername-$$function##-V20",
                  "depends": true,
                  "connections": [
                    {
                      "id": "943eeb9c-28de-468a-8acd-d15760065b92",
                      "interface_id": "d6468d70-2c60-46cc-9a5e-fdaedfe8b6bf",
                      "remote_interface_id": "037e2699-5669-4def-883d-676cb764cadd",
                      "type": "implicit"
                    }
                  ],
                  "interface_type": "Protocols::Server"
                },
                {
                  "id": "392a15cc-1c06-4969-aab5-7fb75febeefe",
                  "name": "default",
                  "depends": true,
                  "connections": [
                    {
                      "id": "1d626a5f-a468-4ffb-b6c5-03605d87fa27",
                      "interface_id": "392a15cc-1c06-4969-aab5-7fb75febeefe",
                      "remote_interface_id": "a6a6d2e4-077c-4ad0-aa54-9af0785c941e",
                      "type": "implicit"
                    }
                  ],
                  "interface_type": "Protocols::SecurityGroup"
                },
                {
                  "id": "8fe41cb0-ff8e-446e-9c05-1240e6463c3d",
                  "name": "eni",
                  "depends": false,
                  "connections": null,
                  "interface_type": "Protocols::NetworkInterface"
                },
                {
                  "id": "c482053e-62ff-429c-a092-808437902154",
                  "name": "atit-subnet1",
                  "depends": true,
                  "connections": [
                    {
                      "id": "18dabab0-0888-4a98-bbd3-6efabc9eebee",
                      "interface_id": "c482053e-62ff-429c-a092-808437902154",
                      "remote_interface_id": "bd4b12c0-cd4d-4551-8007-cb8af3c12f85",
                      "type": "implicit"
                    }
                  ],
                  "interface_type": "Protocols::Subnet"
                },
                {
                  "id": "3fbc30fd-fa85-44c2-9055-49d6184b7b27",
                  "name": "atit-VPC",
                  "depends": true,
                  "connections": [
                    {
                      "id": "717fc325-9f30-4f6e-956a-892b5aae4af6",
                      "interface_id": "3fbc30fd-fa85-44c2-9055-49d6184b7b27",
                      "remote_interface_id": "30f34d56-7767-4f6e-b3d9-e138fc5d21e5",
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
              ],
              "expose": true,
              "parent_id": "172032cb-6c61-459c-9367-47b8f6acdc43",
              "properties": [
                {
                  "name": "private_ips",
                  "value": [
                    {
                      "primary": "true",
                      "privateIpAddress": "",
                      "elasticIp": "",
                      "hasEalsticIp": false
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
                  "value": "",
                  "form_options": {
                    "type": "text",
                    "required": true
                  },
                  "title": "Description"
                },
                {
                  "name": "mac_address",
                  "value": "",
                  "form_options": {
                    "type": "hidden",
                    "readonly": "readonly"
                  },
                  "title": "Mac Address"
                },
                {
                  "name": "source_dest_check",
                  "form_options": {
                    "type": "checkbox",
                    "required": false
                  },
                  "title": "Source Destination Check"
                }
              ]
            },
            "properties": [
              {
                "name": "private_ips",
                "value": [
                  {
                    "primary": "true",
                    "privateIpAddress": "",
                    "elasticIp": "",
                    "hasEalsticIp": false
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
                "value": "",
                "form_options": {
                  "type": "text",
                  "required": true
                },
                "title": "Description"
              },
              {
                "name": "mac_address",
                "value": "",
                "form_options": {
                  "type": "hidden",
                  "readonly": "readonly"
                },
                "title": "Mac Address"
              },
              {
                "name": "source_dest_check",
                "form_options": {
                  "type": "checkbox",
                  "required": false
                },
                "title": "Source Destination Check"
              }
            ],
            "interfaces": [
              {
                "id": "392a15cc-1c06-4969-aab5-7fb75febeefe",
                "name": "default",
                "interface_type": "Protocols::SecurityGroup",
                "depends": true,
                "connections": []
              },
              {
                "id": "d6468d70-2c60-46cc-9a5e-fdaedfe8b6bf",
                "name": "EC2-$$servername-$$function##-V20",
                "interface_type": "Protocols::Server",
                "depends": true,
                "connections": [
                  {
                    "id": "943eeb9c-28de-468a-8acd-d15760065b92",
                    "interface_id": "d6468d70-2c60-46cc-9a5e-fdaedfe8b6bf",
                    "remote_interface_id": "037e2699-5669-4def-883d-676cb764cadd",
                    "internal": false
                  }
                ]
              },
              {
                "id": "8fe41cb0-ff8e-446e-9c05-1240e6463c3d",
                "name": "eni",
                "interface_type": "Protocols::NetworkInterface",
                "depends": false,
                "connections": []
              },
              {
                "id": "c482053e-62ff-429c-a092-808437902154",
                "name": "atit-subnet1",
                "interface_type": "Protocols::Subnet",
                "depends": true,
                "connections": [
                  {
                    "id": "18dabab0-0888-4a98-bbd3-6efabc9eebee",
                    "interface_id": "c482053e-62ff-429c-a092-808437902154",
                    "remote_interface_id": "bd4b12c0-cd4d-4551-8007-cb8af3c12f85",
                    "internal": false
                  }
                ]
              },
              {
                "id": "3fbc30fd-fa85-44c2-9055-49d6184b7b27",
                "name": "atit-VPC",
                "interface_type": "Protocols::Vpc",
                "depends": true,
                "connections": [
                  {
                    "id": "717fc325-9f30-4f6e-956a-892b5aae4af6",
                    "interface_id": "3fbc30fd-fa85-44c2-9055-49d6184b7b27",
                    "remote_interface_id": "30f34d56-7767-4f6e-b3d9-e138fc5d21e5",
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
        "_links": {
          "provision": {
            "href": "/templates/0dede1d3-6ffb-481a-b732-5ceb337f4b7d/provision"
          },
          "clone": {
            "href": "/templates/0dede1d3-6ffb-481a-b732-5ceb337f4b7d/clone"
          },
          "self": {
            "href": "/templates/0dede1d3-6ffb-481a-b732-5ceb337f4b7d/destroy"
          },
          "remove": {
            "href": "/templates/0dede1d3-6ffb-481a-b732-5ceb337f4b7d/destroy"
          },
          "edit": {
            "href": "/templates/0dede1d3-6ffb-481a-b732-5ceb337f4b7d/destroy"
          }
        }
      }';*/


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

      try {

          /* adjust size of cells */
          $this->graphAutoLayout();

          /* draw connection lines */
          $this->drawConnectionLines($graph, $templateModel['services']);

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
