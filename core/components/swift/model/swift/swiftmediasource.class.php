<?php

if (!class_exists('modMediaSource')) {
    require MODX_CORE_PATH . 'model/modx/sources/modmediasource.class.php';
}

class SwiftMediaSource extends modMediaSource implements modMediaSourceInterface
{
    /** @var \OpenCloud\ObjectStore\Service $service */
    public $service;
    /** @var \OpenCloud\ObjectStore\Resource\Container $container */
    public $container;


    /**
     * SwiftMediaSource constructor.
     *
     * @param xPDO $xpdo
     */
    public function __construct(xPDO & $xpdo)
    {
        parent::__construct($xpdo);
        $this->set('is_stream', false);
    }


    /**
     * Initializes Swift media class, connect and get container
     *
     * @return boolean
     */
    public function initialize()
    {
        parent::initialize();
        $this->xpdo->lexicon->load('swift:default');
        $this->xpdo->lexicon->load('core:source');
        $this->properties = $this->getPropertyList();

        if (!class_exists('OpenCloud\Rackspace')) {
            require dirname(dirname(dirname(__FILE__))) . '/vendor/autoload.php';
        }

        return $this->initializeService();
    }


    /**
     * @return bool
     */
    public function initializeService()
    {
        /** @var xPDOCacheManager $cacheManager */
        $cacheManager = $this->xpdo->getCacheManager();
        $key = 'sources/swift-' . $this->get('id');
        try {
            $endpoint = trim($this->xpdo->getOption('authentication_service', $this->properties, ''));
            $client = new OpenCloud\OpenStack($endpoint, array(
                'username' => trim($this->xpdo->getOption('username', $this->properties, '')),
                'password' => trim($this->xpdo->getOption('api_key', $this->properties, '')),
            ));

            if ($data = json_decode($cacheManager->get($key))) {
                if (strtotime($data->token->expires) > time()) {
                    $identity = OpenCloud\Identity\Service::factory($client);
                    $client->setCatalog($data->catalog);
                    $client->setUser($identity->resource('User', $data->user));
                    $client->setTokenObject($identity->resource('Token', $data->token));
                    if (isset($data->token->tenant)) {
                        $client->setTenantObject($identity->resource('Tenant', $data->token->tenant));
                    }
                    $client->setDefaultOption('headers/X-Auth-Token', (string)$data->token->id);
                }
            }

            $this->service = $client->objectStoreService('swift', $this->xpdo->getOption('service', $this->properties, 'ru-1', true));
            $this->container = $this->service->getContainer(array(
                'name' => $this->xpdo->getOption('container', $this->properties, ''),
            ));

            if (!$data && $user = $client->getUser()) {
                $data = array(
                    'user' => array(
                        'id' => $user->getId(),
                        'name' => $user->getUsername(),
                        'roles' => array(),
                    ),
                    'token' => array(
                        'id' => $client->getToken(),
                        'expires' => $client->getExpiration(),
                        'tenant' => array(
                            'id' => $client->getTenant(),
                            'name' => $client->getTenant(),
                        ),
                    ),
                );

                /** @var OpenCloud\Common\Service\Catalog $catalogs */
                $catalogs = $client->getCatalog();
                /** @var OpenCloud\Common\Service\CatalogItem $item */
                $data['catalog'] = array();
                foreach ($catalogs->getItems() as $k => $item) {
                    $data['catalog'][] = array(
                        'endpoints' => $item->getEndpoints(),
                        'type' => $item->getType(),
                        'name' => $item->getName(),
                    );
                }

                $cacheManager->set($key, json_encode($data), strtotime($data['token']['expires']) - time());
            }

            return true;
        } catch (Exception $e) {
            $this->xpdo->log(modX::LOG_LEVEL_ERROR,
                '[SwiftMediaSource] Could not authenticate: ' . $e->getMessage());

            return false;
        }
    }


    /**
     * Get the name of this source type
     *
     * @return string
     */
    public function getTypeName()
    {
        $this->xpdo->lexicon->load('swift:default');

        return $this->xpdo->lexicon('source_type.swift');
    }


    /**
     * Get the description of this source type
     *
     * @return string
     */
    public function getTypeDescription()
    {
        $this->xpdo->lexicon->load('swift:default');

        return $this->xpdo->lexicon('source_type.swift_desc');
    }


    /**
     * @param string $path
     *
     * @return array
     */
    public function getContainerList($path)
    {
        $useMultiByte = $this->xpdo->getOption('use_multibyte', false);
        $encoding = $this->xpdo->getOption('modx_charset', 'UTF-8');
        $hideTooltips = !empty($properties['hideTooltips']) && $properties['hideTooltips'] != 'false';
        $images = array_map('trim', explode(',', $this->getOption('imageExtensions', $properties, 'jpg,jpeg,png,gif')));

        $path = !empty($path)
            ? ltrim($path, '/')
            : '';
        $list = $this->container->objectList(array(
            'path' => $path,
        ));

        $directories = array();
        $files = array();
        /** @var OpenCloud\ObjectStore\Resource\DataObject $obj */
        foreach ($list as $idx => $obj) {
            $currentPath = rtrim(rawurldecode($obj->getName()), DIRECTORY_SEPARATOR);
            $fileName = $this->getBasename($currentPath);
            $contentType = $obj->getContentType();
            // Workaround for display directories
            if (empty($path) && $currentPath != $fileName) {
                if (strpos($currentPath, DIRECTORY_SEPARATOR)) {
                    $tmp = explode(DIRECTORY_SEPARATOR, $currentPath);
                    $fileName = $currentPath = $tmp[0];
                    $contentType = 'application/directory';
                } else {
                    continue;
                }
            }

            if (strtolower($contentType) == 'application/directory') {
                $directories[$currentPath] = array(
                    'id' => $currentPath . '/',
                    'text' => $fileName,
                    'cls' => 'icon-dir',
                    'iconCls' => 'icon icon-folder',
                    'type' => 'dir',
                    'leaf' => false,
                    'path' => $currentPath,
                    'pathRelative' => $currentPath,
                    'perms' => '',
                );
                $directories[$currentPath]['menu'] = array(
                    'items' => $this->getListContextMenu(true, false),
                );
            } else {
                $extension = pathinfo($fileName, PATHINFO_EXTENSION);
                $extension = $useMultiByte
                    ? mb_strtolower($extension, $encoding)
                    : strtolower($extension);
                $files[$currentPath] = array(
                    'id' => $currentPath,
                    'text' => $fileName,
                    'icon' => 'icon-' . $extension,
                    'iconCls' => 'icon icon-file icon-' . $extension,
                    'type' => 'file',
                    'leaf' => true,
                    'path' => $currentPath,
                    'pathRelative' => $currentPath,
                    'lastmod' => (int)strtotime($obj->getLastModified()),
                    'directory' => $currentPath,
                    'url' => rtrim($this->properties['url'], '/') . '/' . $currentPath,
                    'file' => $currentPath,
                );

                $files[$currentPath]['menu'] = array(
                    'items' => $this->getListContextMenu(false, stripos($contentType, 'text') !== 0),
                );
                if (!$hideTooltips) {
                    $files[$currentPath]['qtip'] = in_array($extension, $images)
                        ? '<img src="' . $files[$currentPath]['url'] . '" alt="' . $fileName . '" />'
                        : '';
                }
            }
        }
        $ls = array();
        ksort($directories);
        foreach ($directories as $dir) {
            $ls[] = $dir;
        }

        ksort($files);
        foreach ($files as $file) {
            $ls[] = $file;
        }

        return $ls;
    }


    /**
     * Get the context menu for when viewing the source as a tree
     *
     * @param boolean $isDir
     * @param $isBinary
     *
     * @return array
     */
    public function getListContextMenu($isDir, $isBinary)
    {
        $menu = array();

        if (!$isDir) {
            if ($this->hasPermission('file_update')) {
                if (!$isBinary) {
                    $menu[] = array(
                        'text' => $this->xpdo->lexicon('file_edit'),
                        'handler' => 'this.editFile',
                    );
                    $menu[] = array(
                        'text' => $this->xpdo->lexicon('quick_update_file'),
                        'handler' => 'this.quickUpdateFile',
                    );
                }
                $menu[] = array(
                    'text' => $this->xpdo->lexicon('rename'),
                    'handler' => 'this.renameFile',
                );
            }
            if ($this->hasPermission('file_view')) {
                $menu[] = array(
                    'text' => $this->xpdo->lexicon('file_download'),
                    'handler' => 'this.downloadFile',
                );
            }
            if ($this->hasPermission('file_remove')) {
                if (!empty($menu)) {
                    $menu[] = '-';
                }
                $menu[] = array(
                    'text' => $this->xpdo->lexicon('file_remove'),
                    'handler' => 'this.removeFile',
                );
            }
        } else {
            if ($this->hasPermission('directory_create')) {
                $menu[] = array(
                    'text' => $this->xpdo->lexicon('file_folder_create_here'),
                    'handler' => 'this.createDirectory',
                );
            }
            /*
            if ($this->hasPermission('directory_update')) {
                $menu[] = array(
                    'text' => $this->xpdo->lexicon('rename'),
                    'handler' => 'this.renameDirectory',
                );
            }
            */
            $menu[] = array(
                'text' => $this->xpdo->lexicon('directory_refresh'),
                'handler' => 'this.refreshActiveNode',
            );
            if ($this->hasPermission('file_upload')) {
                $menu[] = '-';
                $menu[] = array(
                    'text' => $this->xpdo->lexicon('upload_files'),
                    'handler' => 'this.uploadFiles',
                );
            }
            if ($this->hasPermission('file_create')) {
                $menu[] = array(
                    'text' => $this->xpdo->lexicon('file_create'),
                    'handler' => 'this.createFile',
                );
                $menu[] = array(
                    'text' => $this->xpdo->lexicon('quick_create_file'),
                    'handler' => 'this.quickCreateFile',
                );
            }
            if ($this->hasPermission('directory_remove')) {
                $menu[] = '-';
                $menu[] = array(
                    'text' => $this->xpdo->lexicon('file_folder_remove'),
                    'handler' => 'this.removeDirectory',
                );
            }
        }

        return $menu;
    }


    /**
     * Get all files in the directory and prepare thumbnail views
     *
     * @param string $path
     *
     * @return array
     */
    public function getObjectsInContainer($path)
    {
        $modAuth = $this->xpdo->user->getUserToken($this->xpdo->context->get('key'));
        // get default settings
        $use_multibyte = $this->xpdo->getOption('use_multibyte', false);
        $encoding = $this->xpdo->getOption('modx_charset', 'UTF-8');
        $containerUrl = rtrim($this->properties['url'], '/') . '/';
        $allowedFileTypes = $this->getOption('allowedFileTypes', $this->properties, '');
        $allowedFileTypes = !empty($allowedFileTypes) && is_string($allowedFileTypes)
            ? array_map('trim', explode(',', $allowedFileTypes))
            : $allowedFileTypes;
        $imageExtensions = $this->getOption('imageExtensions', $this->properties, 'jpg,jpeg,png,gif');
        $imageExtensions = array_map('trim', explode(',', $imageExtensions));
        $thumbnailType = $this->getOption('thumbnailType', $this->properties, 'png');
        $thumbnailQuality = $this->getOption('thumbnailQuality', $this->properties, 90);
        $skipFiles = $this->getOption('skipFiles', $this->properties, '.svn,.git,_notes,.DS_Store');
        $skipFiles = array_map('trim', explode(',', $skipFiles));
        $skipFiles[] = '.';
        $skipFiles[] = '..';
        $thumbWidth = $this->ctx->getOption('filemanager_thumb_width', 100);
        $thumbHeight = $this->ctx->getOption('filemanager_thumb_height', 80);

        $path = !empty($path)
            ? ltrim($path, '/')
            : '';
        $list = $this->container->objectList(array(
            'path' => $path,
        ));
        $files = array();
        /** @var OpenCloud\ObjectStore\Resource\DataObject $obj */
        foreach ($list as $obj) {
            $name = rtrim(rawurldecode($obj->getName()), DIRECTORY_SEPARATOR);
            $baseName = $this->getBasename($name);
            $contentType = $obj->getContentType();
            $objectUrl = $containerUrl . trim($name, '/');
            $isDir = strtolower($contentType) == 'application/directory';
            if (in_array($name, $skipFiles)) {
                continue;
            }

            if (!$isDir) {
                $fileArray = array(
                    'id' => $name,
                    'name' => $baseName,
                    'url' => $objectUrl,
                    'relativeUrl' => $objectUrl,
                    'fullRelativeUrl' => $objectUrl,
                    'pathRelative' => $name,
                    'pathname' => $objectUrl,
                    'lastmod' => (int)strtotime($obj->getLastModified()),
                    'size' => $obj->getContentLength(),
                    'leaf' => true,
                    'menu' => array(
                        array('text' => $this->xpdo->lexicon('file_remove'), 'handler' => 'this.removeFile'),
                    ),
                );

                $fileArray['ext'] = pathinfo($baseName, PATHINFO_EXTENSION);
                $fileArray['ext'] = $use_multibyte
                    ? mb_strtolower($fileArray['ext'], $encoding)
                    : strtolower($fileArray['ext']);
                $fileArray['cls'] = 'icon-' . $fileArray['ext'];
                $fileArray['iconCls'] = 'icon icon-file icon-' . $fileArray['ext'];
                if (!empty($allowedFileTypes) && !in_array($fileArray['ext'], $allowedFileTypes)) {
                    continue;
                }

                // get thumbnail
                if (in_array($fileArray['ext'], $imageExtensions)) {
                    $fileArray['preview'] = 1;
                    $fileArray['image'] = $objectUrl;

                    $thumbQuery = http_build_query(array(
                        'src' => $objectUrl,
                        'w' => $thumbWidth,
                        'h' => $thumbHeight,
                        'f' => $thumbnailType,
                        'q' => $thumbnailQuality,
                        'far' => 1,
                        'HTTP_MODAUTH' => $modAuth,
                        'wctx' => $this->xpdo->context->get('key'),
                        'source' => $this->get('id'),
                    ));
                    $fileArray['thumb'] = $this->xpdo->getOption('connectors_url', MODX_CONNECTORS_URL) .
                        'system/phpthumb.php?' . urldecode($thumbQuery);
                } else {
                    $fileArray['thumb'] = $this->xpdo->getOption('manager_url', MODX_MANAGER_URL) .
                        'templates/default/images/restyle/nopreview.jpg';
                }
                $files[] = $fileArray;
            }
        }

        return $files;
    }


    /**
     * Create a Container
     *
     * @param string $name
     * @param string $parentContainer
     *
     * @return boolean
     */
    public function createContainer($name, $parentContainer)
    {
        $parentContainer = trim($parentContainer, '/') . '/';
        if ($parentContainer == '/' || $parentContainer == '.') {
            $parentContainer = '';
        }
        $newPath = $parentContainer . trim($name, '/');

        try {
            /** @var OpenCloud\ObjectStore\Resource\DataObject $obj */
            $object = $this->container->dataObject()
                ->setName(rawurlencode($newPath))
                ->setContent('')
                ->setContentLength(0)
                ->setDirectory(true)
                ->setContentType('application/directory');
            if ($object->update()) {
                $this->xpdo->logManagerAction('directory_create', '', $newPath);
            }

            return true;
        } catch (Exception $e) {
            $this->xpdo->log(modX::LOG_LEVEL_ERROR,
                "[SwiftMediaSource] Could not create directory \"{$newPath}\": " . $e->getMessage());
            $this->addError('name', $this->xpdo->lexicon('file_folder_err_create') . ':' . $newPath);
        }

        return false;
    }


    /**
     * Rename a container
     *
     * @param string $oldPath
     * @param string $newName
     *
     * @return boolean
     */
    public function renameContainer($oldPath, $newName)
    {
        /*
        try {
            if ($object = $this->container->getObject(rawurlencode($oldPath))) {
                $tmp = explode('/', $oldPath);
                $tmp[count($tmp) - 1] = $newName;
                $newPath = implode('/', $tmp);

                if ($object->copy($newPath)) {
                    $object->delete();
                    $this->xpdo->logManagerAction('directory_rename', '', "{$oldPath} -> {$newPath}");
                }

                return true;
            } else {
                $this->addError('dir', $this->xpdo->lexicon('file_folder_err_nf') . ': ' . $oldPath);
            }
        } catch (Exception $e) {
            $this->xpdo->log(modX::LOG_LEVEL_ERROR,
                "[SwiftMediaSource] Could not rename directory \"{$oldPath}\": " . $e->getMessage());
            $this->addError('name', $this->xpdo->lexicon('file_folder_err_rename') . ': ' . $oldPath);
        }
        */

        return false;
    }


    /**
     * Remove an empty folder
     *
     * @param $path
     *
     * @return boolean
     */
    public function removeContainer($path)
    {
        try {
            if ($list = $this->container->objectList(array('path' => $path))) {
                /** @var OpenCloud\ObjectStore\Resource\DataObject $object */
                foreach ($list as $idx => $object) {
                    $name = rawurldecode($object->getName());
                    if ($object->getContentType() == 'application/directory') {
                        $this->removeContainer($name);
                    } else {
                        $this->removeObject($name);
                    }
                }
                $this->removeObject($path);

                return true;
            } else {
                $this->addError('dir', $this->xpdo->lexicon('file_folder_err_nf') . ': ' . $path);
            }
        } catch (Exception $e) {
            $this->xpdo->log(modX::LOG_LEVEL_ERROR,
                "[SwiftMediaSource] Could not remove directory: \"{$path}\"" . $e->getMessage());
            $this->addError('name', $this->xpdo->lexicon('file_folder_err_remove') . ': ' . $path);
        }

        return false;
    }


    /**
     * Upload files to Swift
     *
     * @param string $path
     * @param array $files
     *
     * @return bool
     */
    public function uploadObjectsToContainer($path, array $files = array())
    {
        $path = trim($path, '/') . '/';
        if ($path == '/' || $path == '.') {
            $path = '';
        }

        $allowedFileTypes = explode(',', $this->xpdo->getOption('upload_files', null, ''));
        $allowedFileTypes = array_merge(explode(',', $this->xpdo->getOption('upload_images')),
            explode(',', $this->xpdo->getOption('upload_media')), explode(',', $this->xpdo->getOption('upload_flash')),
            $allowedFileTypes);
        $allowedFileTypes = array_unique($allowedFileTypes);
        $maxFileSize = $this->xpdo->getOption('upload_maxsize', null, 1048576);

        $objects = array();
        foreach ($files as $file) {
            if ($file['error'] != 0 || empty($file['name'])) {
                continue;
            }
            // Check extension
            $ext = @pathinfo($file['name'], PATHINFO_EXTENSION);
            $ext = strtolower($ext);
            if (empty($ext) || !in_array($ext, $allowedFileTypes)) {
                $this->addError('path', $this->xpdo->lexicon('file_err_ext_not_allowed', array('ext' => $ext)));
                continue;
            }
            // Check size
            $size = @filesize($file['tmp_name']);
            if ($size > $maxFileSize) {
                $this->addError('path', $this->xpdo->lexicon('file_err_too_large', array(
                    'size' => $size,
                    'allowed' => $maxFileSize,
                )));
                continue;
            }

            $objects[] = array(
                'name' => rawurlencode($path . $file['name']),
                'path' => $file['tmp_name'],
            );
        }

        $this->xpdo->invokeEvent('OnFileManagerUpload', array(
            'files' => &$objects,
            'directory' => &$path,
            'source' => &$this,
        ));

        try {
            if ($this->container->uploadObjects($objects)) {
                $this->xpdo->invokeEvent('OnFileManagerUpload', array(
                    'files' => &$objects,
                    'directory' => &$path,
                    'source' => &$this,
                ));
                $this->xpdo->logManagerAction('file_upload', '', $path);

                return true;
            }
        } catch (Exception $e) {
            $this->xpdo->log(modX::LOG_LEVEL_ERROR,
                "[SwiftMediaSource] Could not upload files to \"{$path}\": " . $e->getMessage());
            $this->addError('path', $this->xpdo->lexicon('file_err_upload'));
        }

        return false;
    }


    /**
     * Create an object from a path
     *
     * @param string $path
     * @param string $name
     * @param string $content
     *
     * @return boolean|string
     */
    public function createObject($path, $name, $content)
    {
        $path = trim($path, '/') . '/';
        if ($path == '/' || $path == '.') {
            $path = '';
        }
        $path .= trim($name, " \t\n\r\0\x0B/");

        try {
            /** @noinspection PhpParamsInspection */
            if ($object = $this->container->uploadObject(rawurlencode($path), $content)) {
                $this->xpdo->logManagerAction('file_create', '', $path);

                return $this->getUrl($object, true);
            }
        } catch (Exception $e) {
            $this->xpdo->log(modX::LOG_LEVEL_ERROR,
                "[SwiftMediaSource] Could not create file \"{$path}\": " . $e->getMessage());
            $this->addError('name', $this->xpdo->lexicon('file_err_save') . ': ' . $path);
        }

        return false;
    }


    /**
     * Update the contents of a specific object
     *
     * @param string $path
     * @param string $content
     *
     * @return boolean
     */
    public function updateObject($path, $content)
    {
        try {
            if ($object = $this->container->getObject(rawurlencode($path))) {
                $object->setContent($content);
                if ($object->update()) {
                    $this->xpdo->logManagerAction('file_save', '', $path);
                }

                return true;
            } else {
                $this->addError('file', $this->xpdo->lexicon('file_err_nf') . ': ' . $path);
            }
        } catch (Exception $e) {
            $this->xpdo->log(modX::LOG_LEVEL_ERROR,
                "[SwiftMediaSource] Could not update file \"{$path}\": " . $e->getMessage());
            $this->addError('name', $this->xpdo->lexicon('file_err_save') . ': ' . $path);
        }

        return false;
    }


    /**
     * Rename/move a file
     *
     * @param string $oldPath
     * @param string $newName
     *
     * @return bool
     */
    public function renameObject($oldPath, $newName)
    {
        try {
            if ($object = $this->container->getObject(rawurlencode($oldPath))) {
                $tmp = explode('/', $oldPath);
                $tmp[count($tmp) - 1] = $newName;
                $newPath = implode('/', $tmp);
                $object->setName(rawurlencode($newPath));
                if ($object->update()) {
                    if ($object = $this->container->getPartialObject(rawurlencode($oldPath))) {
                        $object->delete();
                    }
                    $this->xpdo->logManagerAction('file_rename', '', "{$oldPath} -> {$newPath}");
                }

                return true;
            } else {
                $this->addError('file', $this->xpdo->lexicon('file_err_nf') . ': ' . $oldPath);
            }
        } catch (Exception $e) {
            $this->xpdo->log(modX::LOG_LEVEL_ERROR,
                "[SwiftMediaSource] Could not rename file \"{$oldPath}\": " . $e->getMessage());
            $this->addError('name', $this->xpdo->lexicon('file_err_rename') . ': ' . $oldPath);
        }

        return false;
    }


    /**
     * Move a file or folder to a specific location
     *
     * @param string $from The location to move from
     * @param string $to The location to move to
     * @param string $point
     * @param int $to_source
     *
     * @return boolean
     */
    public function moveObject($from, $to, $point = 'append', $to_source = 0)
    {
        if (substr($from, -1) == '/') {
            $this->xpdo->error->message = $this->xpdo->lexicon('s3_no_move_folder', array(
                'from' => $from,
            ));

            return false;
        }

        if ($to == '/' || $to == '.') {
            $to = '';
        }
        if ($point != 'append') {
            $tmp = explode('/', $to);
            unset($tmp[count($tmp) - 1]);
            $to = implode('/', $tmp) . '/';
        }

        $tmp = explode('/', $from);
        $to .= array_pop($tmp);

        if ($from == $to) {
            return true;
        }

        try {
            if ($object = $this->container->getObject(rawurlencode($from))) {
                $object->setName(rawurlencode($to));
                if ($object->update()) {
                    if ($object = $this->container->getPartialObject(rawurlencode($from))) {
                        $object->delete();
                    }
                    $this->xpdo->logManagerAction('file_rename', '', "{$from} -> {$to}");

                    return true;
                }
            } else {
                $this->addError('file', $this->xpdo->lexicon('file_err_nf') . ': ' . $from);
            }
        } catch (Exception $e) {
            $this->xpdo->log(modX::LOG_LEVEL_ERROR,
                "[SwiftMediaSource] Could not move file from \"{$from}\" to \"{$to}\": " . $e->getMessage());
            $this->addError('file', $this->xpdo->lexicon('file_err_rename') . ": \"{$to}\" -> \"{$from}\"");
        }

        return false;
    }


    /**
     * Delete a file
     *
     * @param string $path
     *
     * @return boolean
     */
    public function removeObject($path)
    {
        try {
            if ($object = $this->container->getPartialObject(rawurlencode($path))) {
                if ($object->delete()) {
                    $this->xpdo->logManagerAction('file_remove', '', $path);
                }

                return true;
            } else {
                $this->addError('file', $this->xpdo->lexicon('file_err_ns') . ': ' . $path);
            }
        } catch (Exception $e) {
            $this->xpdo->log(modX::LOG_LEVEL_ERROR,
                "[SwiftMediaSource] Could not remove file \"{$path}\": " . $e->getMessage());
            $this->addError('name', $this->xpdo->lexicon('file_err_remove') . ': ' . $path);
        }

        return false;
    }


    /**
     * Get the contents of a specified file
     *
     * @param string $path
     *
     * @return array
     */
    public function getObjectContents($path)
    {
        $imageExtensions = $this->getOption('imageExtensions', $this->properties, 'jpg,jpeg,png,gif');
        $imageExtensions = array_map('trim', explode(',', $imageExtensions));
        try {
            if ($object = $this->container->getObject(rawurlencode($path))) {
                $content = $object->getContent();
                $name = rawurldecode($object->getName());

                return array(
                    'name' => $name,
                    'basename' => $this->getBasename($name),
                    'path' => $path,
                    'size' => $object->getContentLength(),
                    'last_accessed' => '',
                    'last_modified' => $object->getLastModified(),
                    'content' => (string)$content,
                    'image' => in_array(pathinfo($path, PATHINFO_EXTENSION), $imageExtensions),
                    'is_writable' => $content->isWritable(),
                    'is_readable' => $content->isReadable(),
                );
            }

        } catch (Exception $e) {
            $this->xpdo->log(modX::LOG_LEVEL_ERROR,
                "[SwiftMediaSource] Could not get contents of file \"{$path}\": " . $e->getMessage());
        }

        return array();
    }


    /**
     * @return array
     */
    public function getDefaultProperties()
    {
        $this->xpdo->lexicon->load('swift:default');

        $properties = array(
            'url' => array(
                'name' => 'url',
                'desc' => 'prop_swift.url_desc',
                'type' => 'textfield',
                'options' => '',
                'value' => '',
                'lexicon' => 'swift:default',
            ),
            'container' => array(
                'name' => 'container',
                'desc' => 'prop_swift.container_desc',
                'type' => 'textfield',
                'options' => '',
                'value' => '',
                'lexicon' => 'swift:default',
            ),
            'service' => array(
                'name' => 'service',
                'desc' => 'prop_swift.service_desc',
                'type' => 'textfield',
                'options' => '',
                'value' => 'ru-1',
                'lexicon' => 'swift:default',
            ),
            'authentication_service' => array(
                'name' => 'authentication_service',
                'desc' => 'prop_swift.authentication_service_desc',
                'type' => 'textfield',
                'options' => '',
                'value' => '',
                'lexicon' => 'swift:default',
            ),
            'username' => array(
                'name' => 'username',
                'desc' => 'prop_swift.username_desc',
                'type' => 'password',
                'options' => '',
                'value' => '',
                'lexicon' => 'swift:default',
            ),
            'api_key' => array(
                'name' => 'api_key',
                'desc' => 'prop_swift.api_key_desc',
                'type' => 'password',
                'options' => '',
                'value' => '',
                'lexicon' => 'swift:default',
            ),
            'imageExtensions' => array(
                'name' => 'imageExtensions',
                'desc' => 'prop_swift.imageExtensions_desc',
                'type' => 'textfield',
                'value' => 'jpg,jpeg,png,gif',
                'lexicon' => 'swift:default',
            ),
            'thumbnailType' => array(
                'name' => 'thumbnailType',
                'desc' => 'prop_swift.thumbnailType_desc',
                'type' => 'list',
                'options' => array(
                    array('name' => 'PNG', 'value' => 'png'),
                    array('name' => 'JPG', 'value' => 'jpg'),
                    array('name' => 'GIF', 'value' => 'gif'),
                ),
                'value' => 'png',
                'lexicon' => 'swift:default',
            ),
            'thumbnailQuality' => array(
                'name' => 'thumbnailQuality',
                'desc' => 'prop_swift.thumbnailQuality_desc',
                'type' => 'textfield',
                'options' => '',
                'value' => 90,
                'lexicon' => 'swift:default',
            ),
            'skipFiles' => array(
                'name' => 'skipFiles',
                'desc' => 'prop_swift.skipFiles_desc',
                'type' => 'textfield',
                'options' => '',
                'value' => '.svn,.git,_notes,nbproject,.idea,.DS_Store',
                'lexicon' => 'swift:default',
            ),
        );

        return $properties;
    }


    /**
     * Prepare a src parameter to be rendered with phpThumb
     *
     * @param string $src
     *
     * @return string
     */
    public function prepareSrcForThumb($src)
    {
        return $this->getObjectUrl($src);
    }


    /**
     * Get the base URL for this source. Only applicable to sources that are streams.
     *
     * @param string $object An optional object to find the base url of
     *
     * @return string
     */
    public function getBaseUrl($object = '')
    {
        return $this->properties['url'];
    }


    /**
     * @param string $object
     *
     * @return string
     */
    public function getObjectUrl($object = '')
    {
        $url = trim($this->properties['url'], '/');

        return $url . '/' . ltrim(str_replace($url, '', $object), '/');
    }


    /**
     * @param mixed $object
     * @param bool $relative
     *
     * @return string
     */
    protected function getUrl(OpenCloud\ObjectStore\Resource\DataObject $object, $relative = true)
    {
        $url = '';
        try {
            $url = $object->getUrl();
        } catch (Exception $e) {
            $name = rawurldecode($object->getName());
            $this->xpdo->log(modX::LOG_LEVEL_ERROR,
                "[SwiftMediaSource] Could not get url of object \"{$name}\": " . $e->getMessage());
        }

        if ($relative) {
            $url = ltrim(str_replace($this->container->getUrl(), '', $url), '/');
        }

        return $url;
    }


    /**
     * @param $path
     *
     * @return mixed
     */
    protected function getBasename($path)
    {
        $tmp = explode(DIRECTORY_SEPARATOR, rtrim($path, DIRECTORY_SEPARATOR));

        return array_pop($tmp);
    }

}