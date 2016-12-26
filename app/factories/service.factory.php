<?php

class ServiceFactory {
  private $parent_dimentions = array(
    "vpc"              => Array("w" => 660, "h" => 420),
    "availabilityzone" => Array("w" => 280, "h" => 280),
    "subnet"           => Array("w" => 180, "h" => 180),
    "subnetgroup"      => Array("w" => 160, "h" => 160),
    "autoscaling"      => Array("w" => 100, "h" => 100)
  );

  private $parent_styles = array(
    'vpc'              => "strokeColor=#CCC;dashed=0;fillColor=none;strokeWidth=2;",
    'availabilityzone' => "strokeColor=#7FBDF0;dashed=0;strokeWidth=2;",
    'subnet'           => "strokeColor=#D9CE93;dashed=1;strokeWidth=1;",
    'subnetgroup'      => "strokeColor=#BFB57C;dashed=0;strokeWidth=2;",
    'autoscaling'      => "strokeColor=#A48FC4;dashed=0;strokeWidth=2;",
  );

  public $spacing_x = 20;
  public $spacing_y = 20;
  public $last_child_dim = 56;

  private $internalServiceTypes = ["Protocols::Network", "Protocols::Disk"];

  public function setDimentions($service) {
    $service['dimentions'] = Array(
      "w" => 56,
      "h" => 56
    );

    if (strtolower($service['additional_properties']['service_type']) === 'networkinterface')
    {
      $service['dimentions'] = Array(
        "w" => 36,
        "h" => 36
      );   
    }
    elseif ($this->isParent($service) && isset($this->parent_dimentions[strtolower($service['additional_properties']['service_type'])]))
    {
       $service['dimentions'] = $this->parent_dimentions[strtolower($service['additional_properties']['service_type'])];
    } 
    return $service['dimentions'];
  }

  public function isParent($service) {    
    $service_types = array('vpc', 'subnet', 'availabilityzone', 'subnetgroup', 'autoscaling');
    return in_array($service['additional_properties']['service_type'], $service_types) ? true :false;
  }

  public function loadStyle($service) {    
    $style = '';
    $serviceObjectSVGPath = $this->getImage($service['additional_properties']['generic_type']);
    if (
      $service['type'] && 
      strpos($service['type'], 'RouteTable') !== -1 && 
      array_filter($service['properties'], function($prop
    ) {
      return $prop['name'] === "main" && $prop['value'] == true;
    })) {
      $serviceObjectSVGPath .= "-main";
    }
    else if ($service['type'] && strpos(strtolower($service['type']), 'loadbalancer'))
    {
      $lbScheme = array_filter($service['properties'], function($prop) {
        return $prop['name'] === 'scheme';
      });
      if ($lbScheme && $lbScheme['value'] !== 'internal') {
        $serviceObjectSVGPath .= "-external";
      }
    }
    else if($service['type'] && strpos(strtolower($service['type']), 'database'))
    {

      $dbEngine = '';
      $dbEngines = array_filter($service['properties'], function($prop) {
        return $prop['name'] == 'engine';
      });      
      foreach ($dbEngines as $engins)
      {
        $dbEngine = $engins;
      }

      if ($dbEngine)
      {
        if (strpos(strtolower($dbEngine['value']), 'oracle'))
        {
          $serviceObjectSVGPath .= "-oracle";
        }
        else if (strpos(strtolower($dbEngine['value']), 'sqlserver'))
        {
          $serviceObjectSVGPath .= "-sqlserver-ee";
        }
        else 
        {
          $serviceObjectSVGPath .= "-" . $dbEngine['value'];
        }
      }
      else {
        $serviceObjectSVGPath .= "-" . 'provider'; // change to $provider investigate from where $provider is set
      }
    }
    else if(strtolower($service['additional_properties']['service_type']) === "server" || strtolower($service['additional_properties']['service_type']) === "autoscalingconfiguration")
    {
      $check_win = array_filter($service['properties'], function($prop) {
        return $prop['name'] === 'platform' && $prop['value'] === "windows";
      });
      if ($check_win) {
        $serviceObjectSVGPath .= "-windows" . 'provider';
      } else {
        $serviceObjectSVGPath .= "-" . 'provider';
      }
    }

    /*
    * Added shape= image referring the URL = https://github.com/jbeard4/mxgraph/blob/master/php/examples/embedimage.html
    */

    $style = $this->isParent($service) ? "rounded=2;swimlane;whiteSpace=wrap;fillColor=none;" : "shape=image;image=" . IMAGE_PATH . "/" . $serviceObjectSVGPath . ".png;"; 

    if ($this->isParent($service) && isset($this->parent_styles[strtolower($service['additional_properties']['service_type'])]))
    {
      $style .= $this->parent_styles[strtolower($service['additional_properties']['service_type'])];
    }
    $style .= "whiteSpace=wrap;overflow=hidden";

    return $style;
  }

  public function getImage($serviceObjectSVGPath) {
    return preg_replace('/::/' , "/" , strtolower($serviceObjectSVGPath));
  }

  public function isInternalServiceType($type) {
    return in_array($type, $this->internalServiceTypes);
  }

  public function getExternalFacingInterfaces($service_interfaces) {
    $toReturn = array();
   
    foreach($service_interfaces as $interface)
    {
      if(!$this->isInternalServiceType($interface['interface_type'])) {
        $toReturn[] = $interface;
      }
    }
    return $toReturn;
  }

  public function getInterfaceById($service, $interfaceId) {
    return array_filter($service['interfaces'], function($intf) use($interfaceId){
      return $intf['id'] === $interfaceId;
    });
  }

  public function addConnectorPorts($graph, $parentCell) {
    $serviceConnectorMap = Array(
      "routetable"      =>  Array('subnet'=>"up", 'server'=>"left", 'internetgateway'=>"down", 'networkinterface'=>"right"),
      'loadbalancer'    =>  Array('subnet'=>"up", 'server'=>"down", 'autoscaling'=>"left"),
      "subnet"          =>  Array("routetable"=>"up", 'loadbalancer'=>"right"),
      "server"          =>  Array("routetable"=>"up", 'loadbalancer'=>"right", "networkinterface"=>"left"),
      "autoscaling"     =>  Array("loadbalancer"=>"right"),
      "internetgateway" =>  Array('routetable'=>"right")
    );

    if (!array_key_exists(strtolower($parentCell->value['additional_properties']['service_type']), $serviceConnectorMap))
    {
      return;
    }

    if (!$parentCell->parent->value)
    {
      return;
    }

    foreach($serviceConnectorMap[strtolower($parentCell->value['additional_properties']['service_type'])] as $connectorType => $direction)
    {
      $vpcIGAttachProp =array_filter($parentCell->parent->value['properties'], function($prop) {
        return $prop["name"] == 'internet_attached';
      }); 

      if(strtolower($parentCell->value['additional_properties']["service_type"]) != 'routetable' || 
        (strtolower($parentCell->value['additional_properties']["service_type"]) == 'routetable' && ($connectorType != 'internetgateway' || ($connectorType == 'internetgateway' && count($vpcIGAttachProp)>0)))
        ) 
      {
        
        $connVal = array(
          'service_type'              => $connectorType,
          "direction"                 => $direction,
          'generic_type'              =>'connectors',
          "name"                      =>"",
          "interface_definitions"     =>array("provides"=>'', 'depends'=>''),
          "findSharedInterfaces"      =>function(){},
          "removeConnectionToService" =>function(){},
          "createId"                  =>function(){},
          "addInterface"              =>function(){},
          'provides'                  =>array(array('type'=>''))
          );

        $port = $graph->insertVertex(
          $parentCell, null, $connVal, 0, 0, 15, 15,
          'shape=image;image='.IMAGE_PATH.'/connector-'.$direction.'.png;align=center;imageAlign=center;spacingRight=1;imageWidth=16;imageHeight=16;', true);
        
        $port->setVisible($parentCell->isVisible());
        
        $this->setPortPlacement($parentCell, $port);
      }
    }  
  }

  public function setPortPlacement($parentCell, $port) {
    if(!$port->geometry) {          
      return;
    } 

   $extra = strtolower($parentCell->value['additional_properties']['service_type']) == 'subnet' ? 1 : (strtolower($parentCell->value['additional_properties']['service_type']) == 'autoscaling' ? 0 : -4);

    $port->geometry->x = $port->geometry->y = 0;

    if ($port->value['direction'] == 'right') {
      $port->geometry->offset = new mxPoint($parentCell->geometry->width + $extra,  $parentCell->geometry->height/2-$port->geometry->height/2);
    }
    else if ($port->value['direction'] == 'up') {                
      $port->geometry->offset = new mxPoint($parentCell->geometry->width/2-$port->geometry->width/2, -($port->geometry->height + $extra));
    }
    else if ($port->value['direction'] == 'left') {                
      $port->geometry->offset = new mxPoint(-($port->geometry->width + $extra), $parentCell->geometry->height/2+$extra);
    }
    else if($port->value['direction'] == 'down'){                
      $port->geometry->offset = new mxPoint($parentCell->geometry->width/2-$port->geometry->width/2, $parentCell->geometry->height + $extra);
    }
    $port->geometry->relative = true;   
  }

  public function saveLog($data) {
    try {
      $file = fopen('logs.txt', 'a+');
      fwrite($file, "\n".$data);
      fclose($file);
    } catch(Exception $e) {
      print_R($e);
      exit;
    }
  }

  public function clearLog() {
    try {
      $file = fopen('logs.txt', 'w');
      fwrite($file, "");
      fclose($file);
    } catch(Exception $e) {
      print_R($e);
      exit;
    }
  }

  public function getStrokeColor($sourceService) {
    $strokeColors = array(
      "internetgateway" => "#589cc4",
      "autoscaling" => "#6fdbc2",
      "subnet"=>"#ffa3a3"
    );
    if (array_key_exists(strtolower($sourceService), $strokeColors))
    {
      return $strokeColors[strtolower($sourceService)];
    }
    return "#7FBDF0";
  }

  public function isJson($string) {
    json_decode($string);
    return (json_last_error() == JSON_ERROR_NONE);
  }

  public function get_dimemtions_max($maxlist) {    
    $finalDim = array('w'=> 0, 'h'=> 0);
    foreach($maxlist as $l)
    {
        $finalDim['w'] = ($finalDim['w'] < $l['w']) ? $l['w'] : $finalDim['w'];
        $finalDim['h'] = ($finalDim['h'] < $l['h']) ? $l['h'] : $finalDim['h'];
    };
    $finalDim['h'] = $finalDim['h'] + 20;
    $finalDim['w'] = $finalDim['w'] + 20 + $this->spacing_x;
    return $finalDim;
  }
}

?>