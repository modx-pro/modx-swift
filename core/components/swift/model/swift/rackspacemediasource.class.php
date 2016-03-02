<?php

if (!class_exists('SwiftMediaSource')) {
    require dirname(__FILE__) . '/swiftmediasource.class.php';
}

class RackspaceMediaSource extends SwiftMediaSource
{

    /**
     * @return bool
     */
    public function initializeService()
    {
        try {
            $endpoint = $this->xpdo->getOption('authentication_service', $this->properties,
                OpenCloud\Rackspace::US_IDENTITY_ENDPOINT);
            $client = new OpenCloud\Rackspace(trim($endpoint), array(
                'username' => trim($this->xpdo->getOption('username', $this->properties, '')),
                'apiKey' => trim($this->xpdo->getOption('api_key', $this->properties, '')),
            ));

            $region = $this->xpdo->getOption('region', $this->properties, 'DWF');
            $this->service = $client->objectStoreService(null, trim($region));
            $this->container = $this->service->getContainer(array(
                'name' => $this->xpdo->getOption('container', $this->properties, ''),
            ));

            return true;
        } catch (Exception $e) {
            $this->xpdo->log(modX::LOG_LEVEL_ERROR,
                '[RackspaceMediaSource] Could not authenticate: ' . $e->getMessage());

            return false;
        }
    }


    /**
     * Get the name of this source type
     * @return string
     */
    public function getTypeName()
    {
        $this->xpdo->lexicon->load('swift:default');

        return $this->xpdo->lexicon('source_type.rackspace');
    }


    /**
     * Get the description of this source type
     * @return string
     */
    public function getTypeDescription()
    {
        $this->xpdo->lexicon->load('swift:default');

        return $this->xpdo->lexicon('source_type.rackspace_desc');
    }


    /**
     * @return array
     */
    public function getDefaultProperties()
    {
        $properties = parent::getDefaultProperties();
        $properties['region'] = array(
            'name' => 'region',
            'desc' => 'prop_swift.region_desc',
            'type' => 'list',
            'options' => array(
                array('name' => 'Dallas-Fort Worth', 'value' => 'DFW'),
                array('name' => 'Chicago', 'value' => 'ORD'),
                array('name' => 'Northern Virginia', 'value' => 'IAD'),
                array('name' => 'London', 'value' => 'LON'),
                array('name' => 'Sydney', 'value' => 'SYD'),
                array('name' => 'Hong Kong', 'value' => 'HKG'),
            ),
            'value' => 'DFW',
            'lexicon' => 'swift:default',
        );

        return $properties;
    }

}