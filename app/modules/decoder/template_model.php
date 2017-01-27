<?php
include_once(BASE_DIR. 'app/factories/service.factory.php');

class TemplateModel {
  public $serviceFactory;

  public function __construct() {
    $this->serviceFactory = new ServiceFactory();
  }
  /**
  * Returns a service that contains the specified interface, or contains a child service with the interface. Used in decoding to
  * determine subnet connections.
  * @param {uuid} interface_id The uuid of the interface
  * @returns {Service} The top level service related to this interface ID
  */
  public function findParentServiceByInterfaceId ($services, $interface_id) {
    return array_filter($services, function($service) use($interface_id) {  
      return !$service['internal'] && $this->serviceFactory->getInterfaceById($service, $interface_id);
    });
  }
}

?>