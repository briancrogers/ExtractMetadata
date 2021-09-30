<?php
namespace ExtractMetadata;

use DateTime;
use Doctrine\Common\Collections\Criteria;
use ExtractMetadata\Entity\ExtractMetadata;
use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\Form\Element;
use Laminas\ModuleManager\ModuleManager;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Entity;
use Omeka\File\Store\Local;
use Omeka\Module\AbstractModule;
use Rs\Json\Pointer;

class Module extends AbstractModule
{
    const ACTIONS = [
        'refresh' => 'Refresh metadata', // @translate
        'refresh_map' => 'Refresh and map metadata', // @translate
        'map' => 'Map metadata', // @translate
    ];

    public function init(ModuleManager $moduleManager)
    {
        require_once sprintf('%s/vendor/autoload.php', __DIR__);
    }

    public function getConfig()
    {
        return array_merge(
            include sprintf('%s/config/module.config.php', __DIR__),
            include sprintf('%s/config/crosswalk.config.php', __DIR__)
        );
    }

    public function install(ServiceLocatorInterface $services)
    {
        $sql = <<<'SQL'
CREATE TABLE extract_metadata (id INT UNSIGNED AUTO_INCREMENT NOT NULL, media_id INT NOT NULL, extracted DATETIME NOT NULL, extractor VARCHAR(255) NOT NULL, metadata LONGTEXT NOT NULL COMMENT '(DC2Type:json)', INDEX IDX_4DA36818EA9FDD75 (media_id), UNIQUE INDEX media_extractor (media_id, extractor), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;
ALTER TABLE extract_metadata ADD CONSTRAINT FK_4DA36818EA9FDD75 FOREIGN KEY (media_id) REFERENCES media (id) ON DELETE CASCADE;
SQL;
        $conn = $services->get('Omeka\Connection');
        $conn->exec('SET FOREIGN_KEY_CHECKS=0;');
        $conn->exec($sql);
        $conn->exec('SET FOREIGN_KEY_CHECKS=1;');
    }

    public function uninstall(ServiceLocatorInterface $services)
    {
        $conn = $services->get('Omeka\Connection');
        $conn->exec('SET FOREIGN_KEY_CHECKS=0;');
        $conn->exec('DROP TABLE IF EXISTS extract_metadata;');
        $conn->exec('SET FOREIGN_KEY_CHECKS=1;');
    }

    public function getConfigForm(PhpRenderer $view)
    {
        $services = $this->getServiceLocator();
        $extractors = $services->get('ExtractMetadata\ExtractorManager');
        $config = $services->get('Config');
        $crosswalk = $config['extract_metadata_crosswalk'];
        return $view->partial('common/extract-metadata-config-form', [
            'extractors' => $extractors,
            'crosswalk' => $crosswalk,
        ]);
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager)
    {
        /*
         * Extract metadata before ingesting media file. This will only happen
         * when creating the media.
         */
        $sharedEventManager->attach(
            '*',
            'media.ingest_file.pre',
            function (Event $event) {
                $mediaEntity = $event->getTarget();
                $tempFile = $event->getParam('tempFile');
                $metadataEntities = $this->extractMetadata(
                    $tempFile->getTempPath(),
                    $tempFile->getMediaType(),
                    $mediaEntity
                );
                $this->mapMetadata($mediaEntity, $metadataEntities);
            }
        );
        /*
         * After hydrating a media, perform the requested extract_metadata_action.
         * This will only happen when updating the media.
         */
        $sharedEventManager->attach(
            'Omeka\Api\Adapter\MediaAdapter',
            'api.hydrate.post',
            function (Event $event) {
                $request = $event->getParam('request');
                if ('update' !== $request->getOperation()) {
                    // This is not an update operation.
                    return;
                }
                $mediaEntity = $event->getParam('entity');
                $data = $request->getContent();
                $action = $data['extract_metadata_action'] ?? 'default';
                $this->performAction($mediaEntity, $action);
            }
        );
        /*
         * After hydrating an item, perform the requested extract_metadata_action.
         * This will only happen when updating the item.
         */
        $sharedEventManager->attach(
            'Omeka\Api\Adapter\ItemAdapter',
            'api.hydrate.post',
            function (Event $event) {
                $request = $event->getParam('request');
                if ('update' !== $request->getOperation()) {
                    // This is not an update operation.
                    return;
                }
                $item = $event->getParam('entity');
                $data = $request->getContent();
                $action = $data['extract_metadata_action'] ?? 'default';
                foreach ($item->getMedia() as $mediaEntity) {
                    $this->performAction($mediaEntity, $action);
                }
            }
        );
        /*
         * Add the ExtractMetadata control to the media batch update form.
         */
        $sharedEventManager->attach(
            'Omeka\Form\ResourceBatchUpdateForm',
            'form.add_elements',
            function (Event $event) {
                $form = $event->getTarget();
                $resourceType = $form->getOption('resource_type');
                if (!in_array($resourceType, ['media', 'item'])) {
                    // This is not a media or item batch update form.
                    return;
                }
                $element = $this->getActionSelect();
                $element->setAttribute('data-collection-action', 'replace');
                $form->add($element);
            }
        );
        /*
         * Don't require the ExtractMetadata control in the batch update forms.
         */
        $sharedEventManager->attach(
            'Omeka\Form\ResourceBatchUpdateForm',
            'form.add_input_filters',
            function (Event $event) {
                $form = $event->getTarget();
                $resourceType = $form->getOption('resource_type');
                if (!in_array($resourceType, ['media', 'item'])) {
                    // This is not a media or item batch update form.
                    return;
                }
                $inputFilter = $event->getParam('inputFilter');
                $inputFilter->add([
                    'name' => 'extract_metadata_action',
                    'required' => false,
                ]);
            }
        );
        /*
         * Authorize the "extract_metadata_action" key when preprocessing the
         * batch update data. This will signal the process to refresh or map the
         * metadata while updating each resource in the batch.
         */
        $preprocessBatchUpdate = function (Event $event) {
            $adapter = $event->getTarget();
            $data = $event->getParam('data');
            $rawData = $event->getParam('request')->getContent();
            if (isset($rawData['extract_metadata_action'])
                && in_array($rawData['extract_metadata_action'], array_keys(self::ACTIONS))
            ) {
                $data['extract_metadata_action'] = $rawData['extract_metadata_action'];
            }
            $event->setParam('data', $data);
        };
        $sharedEventManager->attach(
            'Omeka\Api\Adapter\MediaAdapter',
            'api.preprocess_batch_update',
            $preprocessBatchUpdate
        );
        $sharedEventManager->attach(
            'Omeka\Api\Adapter\ItemAdapter',
            'api.preprocess_batch_update',
            $preprocessBatchUpdate
        );
        /*
         * Add an "Extract metadata" tab to the item and media edit pages.
         */
        $viewEditSectionNav = function (Event $event) {
            $view = $event->getTarget();
            $sectionNavs = $event->getParam('section_nav');
            $sectionNavs['extract-metadata'] = $view->translate('Extract metadata');
            $event->setParam('section_nav', $sectionNavs);
        };
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Item',
            'view.edit.section_nav',
            $viewEditSectionNav
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Media',
            'view.edit.section_nav',
            $viewEditSectionNav
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Media',
            'view.show.section_nav',
            $viewEditSectionNav
        );
        /*
         * Add an "Extract metadata" section to the item and media edit pages.
         */
        $viewEditFormAfter = function (Event $event) {
            $view = $event->getTarget();
            $store = $this->getServiceLocator()->get('Omeka\File\Store');
            $entityManager = $this->getServiceLocator()->get('Omeka\EntityManager');
            // Set the form element, if needed.
            $element = null;
            if ('view.show.after' !== $event->getName()) {
                $element = $this->getActionSelect();
            }
            // Set the metadata entity, if needed.
            $metadataEntities = [];
            if ($view->media) {
                $metadataEntities = $entityManager->getRepository(ExtractMetadata::class)
                    ->findBy(['media' => $view->media->id()]);
            }
            echo $view->partial('common/extract-metadata-section', [
                'element' => $element,
                'metadataEntities' => $metadataEntities,
            ]);
        };
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Item',
            'view.edit.form.after',
            $viewEditFormAfter
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Media',
            'view.edit.form.after',
            $viewEditFormAfter
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Media',
            'view.show.after',
            $viewEditFormAfter
        );
    }

    /**
     * Extract metadata.
     *
     * @param string $filePath
     * @param string $mediaType
     * @param Entity\Media $mediaEntity
     * @return array An array of metadata entities
     */
    public function extractMetadata($filePath, $mediaType, Entity\Media $mediaEntity)
    {
        if (!@is_file($filePath)) {
            // The file doesn't exist.
            return;
        }
        $extractors = $this->getServiceLocator()->get('ExtractMetadata\ExtractorManager');
        $entityManager = $this->getServiceLocator()->get('Omeka\EntityManager');
        $metadataEntities = [];
        foreach ($extractors->getRegisteredNames() as $extractorName) {
            $extractor = $extractors->get($extractorName);
            if (!$extractor->isAvailable()) {
                // The extractor is unavailable.
                continue;
            }
            if (!$extractor->canExtract($mediaType)) {
                // The extractor does not support this media type.
                continue;
            }
            $metadata = $extractor->extract($filePath, $mediaType);
            if (!is_array($metadata)) {
                // The extractor did not return an array.
                continue;
            }
            // Avoid JSON and character encoding errors by encoding the metadata
            // into JSON and back into an array, ignoring invalid UTF-8. Invalid
            // JSON would break on the Doctrine level, so we include this here.
            $metadata = json_decode(json_encode($metadata, JSON_INVALID_UTF8_IGNORE), true);
            if (!$metadata) {
                // Could not convert array to JSON.
                continue;
            }
            // Create the metadata entity.
            $metadataEntity = $entityManager->getRepository(ExtractMetadata::class)
                ->findOneBy([
                    'media' => $mediaEntity,
                    'extractor' => $extractorName,
                ]);
            if (!$metadataEntity) {
                $metadataEntity = new ExtractMetadata;
                $metadataEntity->setMedia($mediaEntity);
                $metadataEntity->setExtractor($extractorName);
                $entityManager->persist($metadataEntity);
            }
            $metadataEntity->setExtracted(new DateTime('now'));
            $metadataEntity->setMetadata($metadata);
            $metadataEntities[] = $metadataEntity;
        }
        return $metadataEntities;
    }

    /**
     * Map metadata using the crosswalk.
     *
     * Uses JSON Pointer for PHP to query the JSON.
     *
     * @see https://github.com/raphaelstolt/php-jsonpointer
     * @param Entity\Media $mediaEntity
     * @param array $metadataEntities An array of metadata entities
     */
    public function mapMetadata(Entity\Media $mediaEntity, array $metadataEntities)
    {
        $config = $this->getServiceLocator()->get('Config');
        $entityManager = $this->getServiceLocator()->get('Omeka\EntityManager');
        $crosswalk = $config['extract_metadata_crosswalk'];
        $itemEntity = $mediaEntity->getItem();
        $mediaValues = $mediaEntity->getValues();
        $itemValues = $itemEntity->getValues();
        $propertiesToClear = ['media' => [], 'item' =>[]];
        $valuesToAdd = ['media' => [], 'item' => []];
        foreach ($metadataEntities as $metadataEntity) {
            $extractor =  $metadataEntity->getExtractor();
            if (!isset($crosswalk[$extractor])) {
                // This extractor has no mappings.
                continue;
            }
            foreach ($crosswalk[$extractor] as $map) {
                if (!isset($map['media'], $map['item'], $map['pointer'], $map['term'], $map['replace'])) {
                    // All keys are required.
                    continue;
                }
                $property = $this->getPropertyByTerm($map['term']);
                if (!$property) {
                    // This term has no property.
                    continue;
                }
                if ($map['replace']) {
                    // Set properties to clear if configured to do so.
                    if ($map['media']) {
                        $propertiesToClear['media'][] = $property;
                    }
                    if ($map['item']) {
                        $propertiesToClear['item'][] = $property;
                    }
                }
                try {
                    $jsonPointer = new Pointer(json_encode($metadataEntity->getMetadata()));
                    $valueString = $jsonPointer->get($map['pointer']);
                } catch (\Exception $e) {
                    // Invalid JSON, invalid pointer, or nonexistent value.
                    continue;
                }
                if (!is_string($valueString)) {
                    // The pointer did not resolve to a string.
                    continue;
                }
                // Create and add the value.
                if ($map['media']) {
                    $value = new Entity\Value;
                    $value->setResource($mediaEntity);
                    $value->setType('literal');
                    $value->setProperty($property);
                    $value->setValue($valueString);
                    $value->setIsPublic(true);
                    $valuesToAdd['media'][] = $value;
                }
                if ($map['item']) {
                    $value = new Entity\Value;
                    $value->setResource($itemEntity);
                    $value->setType('literal');
                    $value->setProperty($property);
                    $value->setValue($valueString);
                    $value->setIsPublic(true);
                    $valuesToAdd['item'][] = $value;
                }
            }
        }
        // Remove values.
        foreach ($propertiesToClear['media'] as $property) {
            $criteria = Criteria::create()->where(Criteria::expr()->eq('property', $property));
            foreach ($mediaValues->matching($criteria) as $mediaValue) {
                $mediaValues->removeElement($mediaValue);
            }
        }
        foreach ($propertiesToClear['item'] as $property) {
            $criteria = Criteria::create()->where(Criteria::expr()->eq('property', $property));
            foreach ($itemValues->matching($criteria) as $itemValue) {
                $itemValues->removeElement($itemValue);
            }
        }
        // Add values.
        foreach ($valuesToAdd['media'] as $value) {
            $mediaValues->add($value);
        }
        foreach ($valuesToAdd['item'] as $value) {
            $itemValues->add($value);
        }
    }

    /**
     * Perform an extract metadata action.
     *
     * @param Entity\Media $mediaEntity
     * @param string $action
     */
    public function performAction(Entity\Media $mediaEntity, $action)
    {
        if (!in_array($action, array_keys(self::ACTIONS))) {
            // This is an invalid action.
            return;
        }
        switch ($action) {
            case 'refresh':
                // Files must be stored locally to refresh extracted metadata.
                $store = $this->getServiceLocator()->get('Omeka\File\Store');
                if ($store instanceof Local) {
                    $filePath = $store->getLocalPath(sprintf('original/%s', $mediaEntity->getFilename()));
                    $this->extractMetadata($filePath, $mediaEntity->getMediaType(), $mediaEntity);
                }
                break;
            case 'refresh_map':
                // Files must be stored locally to refresh extracted metadata.
                $store = $this->getServiceLocator()->get('Omeka\File\Store');
                if ($store instanceof Local) {
                    $filePath = $store->getLocalPath(sprintf('original/%s', $mediaEntity->getFilename()));
                    $metadataEntities = $this->extractMetadata($filePath, $mediaEntity->getMediaType(), $mediaEntity);
                    $this->mapMetadata($mediaEntity, $metadataEntities);
                }
                break;
            case 'map':
                $metadataEntities = $this->getServiceLocator()
                    ->get('Omeka\EntityManager')
                    ->getRepository(ExtractMetadata::class)
                    ->findBy(['media' => $mediaEntity]);
                $this->mapMetadata($mediaEntity, $metadataEntities);
                break;
        }
    }

    /**
     * Get property by term.
     *
     * @param string $term vocabularyPrefix:propertyLocalName
     * @return null|Entity\Property
     */
    public function getPropertyByTerm($term)
    {
        $entityManager = $this->getServiceLocator()->get('Omeka\EntityManager');
        list($prefix, $localName) = array_pad(explode(':', $term), 2, null);
        $dql = '
            SELECT p
            FROM Omeka\Entity\Property p
            JOIN p.vocabulary v
            WHERE p.localName = :localName
            AND v.prefix = :prefix';
        $query = $entityManager->createQuery($dql);
        $query->setParameters([
            'localName' => $localName,
            'prefix' => $prefix,
        ]);
        return $query->getOneOrNullResult();
    }

    /**
     * Get action select element.
     *
     * @return Element\Select
     */
    public function getActionSelect()
    {
        $valueOptions = [
            'map' => self::ACTIONS['map'],
        ];
        $store = $this->getServiceLocator()->get('Omeka\File\Store');
        if ($store instanceof Local) {
            // Files must be stored locally to refresh extracted metadata.
            $valueOptions = [
                'refresh' => self::ACTIONS['refresh'],
                'refresh_map' => self::ACTIONS['refresh_map'],
            ] + $valueOptions;
        }
        $element = new Element\Select('extract_metadata_action');
        $element->setLabel('Extract metadata');
        $element->setEmptyOption('[No action]'); // @translate
        $element->setValueOptions($valueOptions);
        return $element;
    }
}
