<?php
/**
 * Created by PhpStorm.
 * User: mike
 * Date: 5/7/19
 * Time: 5:33 PM
 */

namespace DataHub\Command;


use DataHub\ResourceAPIBundle\Document\Record;
use DOMDocument;
use DOMXPath;
use Phpoaipmh\Endpoint;
use Phpoaipmh\Exception\OaipmhException;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class FillLocalDatahubCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('app:import-data')
            ->addArgument("url", InputArgument::OPTIONAL, "The URL of the Datahub")
            ->setDescription('Fetches 200 relevant records from a remote Datahub, enriches it with copyright and language data and stores it in the local Datahub.')
            ->setHelp('This command fetches the 200 relevant records from a remote Datahub, enriches it with copyright and language data and stores it in the local Datahub.\nOptional parameter: the URL of the remote Datahub. If the URL equals "skip", it will not fetch data.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $url = $input->getArgument("url");
        if(!$url) {
            $url = $this->getContainer()->getParameter('remote_datahub_url');
        }

        $verbose = $input->getOption('verbose');

        $namespace = $this->getContainer()->getParameter('datahub.namespace');
        $metadataPrefix = $this->getContainer()->getParameter('datahub.metadataprefix');
        $dataDef = $this->getContainer()->getParameter('data_definition');

        // Fetch the record ID's for the records that we have to import from the remote Datahub
        $csvFolder = $this->getContainer()->getParameter('csv_folder');
        $recordInfo = $this->readRecordIdsFromCsv($csvFolder . $this->getContainer()->getParameter('record_ids_csv_file'));

        // Fetch all translations of all the fields to alter or translate the lido data
        $translations = array();
        foreach ($dataDef as $key => $value) {
            $translations[$key] = $this->readTranslationsFromCsv($csvFolder . $value['csv_file']);
        }

        // Fetch all possible languages as defined in the CSV-files
        $languages = array();
        foreach($translations as $key => $translation) {
            $headers = $this->getHeadersFromCsv($csvFolder . $value['csv_file']);
            foreach($headers as $header) {
                if($header != 'Work PID' && !in_array($header, $languages)) {
                    $languages[] = $header;
                }
            }
        }

        $dm = $this->getContainer()->get('doctrine_mongodb')->getManager();

        // Remove all current data in the local database
        $dm->getDocumentCollection('DataHubResourceAPIBundle:Record')->remove([]);
        $dm->flush();

        // Build the OAI-PMH client
        $myEndpoint = Endpoint::build($url);

        foreach($recordInfo as $record) {

            try {
                $dataPid = $record['dataPid'];

                $recordRepository = $this->getContainer()->get('datahub.resource_api.repository.default');
                $oldRecord = $recordRepository->findOneByProperty('recordIds', $dataPid);
                if ($oldRecord instanceof Record) {
                    echo 'Record with ID ' . $dataPid . ' already exists.' . PHP_EOL;
                } else {
                    $rec = $myEndpoint->getRecord($dataPid, $metadataPrefix);

                    $data = $rec->GetRecord->record->metadata->children($namespace, true);

                    //Fetch the data from this record based on data_definition in data_import.yml
                    $newData = $this->alterData($dataDef, $namespace, $languages, $translations, $recordInfo, $data, $record['workPid'], $record['isPartOf'], $record['hasPart'], $record['copyrightStatus'], $record['lukasPhotoId']);

                    $doc = new \DOMDocument();
                    $doc->formatOutput = true;
                    $doc->loadXML($newData);

                    $doc->getElementsByTagName("lidoRecID")->item(0)->nodeValue = $dataPid;
                    $raw = $doc->saveXML();

                    $record = new Record();
                    $record->setRecordIds(array($dataPid));
                    $record->setObjectIds(array());
                    $record->setRaw($raw);

                    $manager = $this->getContainer()->get('doctrine_mongodb')->getManager();
                    $manager->persist($record);
                    $manager->flush();
                }
            }
            catch(OaipmhException $e) {
                echo $e->getMessage() . PHP_EOL;
            }
        }
    }

    private function readRecordIdsFromCsv($csvFile)
    {
        $csv = array();
        $i = 0;
        if (($handle = fopen($csvFile, "r")) !== false) {
            $columns = fgetcsv($handle, 1000, ",");
            while (($row = fgetcsv($handle, 1000, ",")) !== false) {
                if(count($columns) != count($row)) {
                    echo 'Wrong column count: should be ' . count($columns) . ', is ' . count($row) . ' at row ' . $i;
                }
                $line = array_combine($columns, $row);
                $add = true;

                // Merge copyright statuses and photo ID's for lines with the same work PID
                for($j = 0; $j < $i; $j++) {
                    if($csv[$j]['workPid'] == $line['workPid']) {
                        $csv[$j]['copyrightStatus'] = $csv[$j]['copyrightStatus'] . ' ; ' . $line['copyrightStatus'];
                        $csv[$j]['lukasPhotoId'] = $csv[$j]['lukasPhotoId'] . ' ; ' . $line['lukasPhotoId'];
                        $add = false;
                        break;
                    }
                }
                if($add) {
                    $csv[$i] = $line;
                    $i++;
                }
            }
            fclose($handle);
        }

        return $csv;
    }

    private function getHeadersFromCsv($csvFile)
    {
        $columns = array();
        if (($handle = fopen($csvFile, "r")) !== false) {
            $columns = fgetcsv($handle, 1000, ",");
            fclose($handle);
        }
        return $columns;
    }

    private function readTranslationsFromCsv($csvFile)
    {
        $csv = array();
        $i = 0;
        if (($handle = fopen($csvFile, "r")) !== false) {
            $columns = fgetcsv($handle, 1000, ",");
            while (($row = fgetcsv($handle, 1000, ",")) !== false) {
                $csv[$i] = array_combine($columns, $row);
                $i++;
            }
            fclose($handle);
        }
        return $csv;
    }

    // Remove old nodes and insert new nodes, or translate nodes where applicable
    private function alterData($dataDef, $namespace, $languages, $translations, $recordInfo, $data, $workPid, $isPartsOfLine, $hasPartsLine, $rightsStatusesLine, $photoIdsLine)
    {
        // Create a new DOMDocument based on the data we retrieved from the remote Datahub
        $domDoc = new DOMDocument;
        $domDoc->preserveWhiteSpace = false;
        $domDoc->formatOutput = true;
        $domDoc->loadXML($data->asXML());
        $xpath = new DOMXPath($domDoc);

        $query = 'descendant::lido:descriptiveMetadata';
        $defaultDescriptiveMetadata = null;
        $defaultDescriptiveMetadatas = $xpath->query($query);
        foreach($defaultDescriptiveMetadatas as $def) {
            $defaultDescriptiveMetadata = $def;
        }

        $query = 'descendant::lido:administrativeMetadata';
        $defaultAdministrativeMetadata = null;
        $defaultAdministrativeMetadatas = $xpath->query($query);
        foreach($defaultAdministrativeMetadatas as $def) {
            $defaultAdministrativeMetadata = $def;
        }

        $this->addRelatedWorksWrap($namespace, $recordInfo, $workPid, $isPartsOfLine, $hasPartsLine, $domDoc, $defaultDescriptiveMetadata);

        $this->addPhotosAndCopyright($namespace, $rightsStatusesLine, $photoIdsLine, $domDoc, $defaultAdministrativeMetadata);

        foreach ($languages as $language) {

            // Clone the descriptive metadata element if it is not present yet for this language
            $query = $this->buildXpath('descriptiveMetadata[@xml:lang="{language}"]', $namespace, $language);
            $descriptiveMetadatas = $xpath->query($query);
            $makeNew = false;
            if(!$descriptiveMetadatas) {
                $makeNew = true;
            } else if($descriptiveMetadatas->length == 0) {
                $makeNew = true;
            }
            if($makeNew) {
                $newNode = $defaultDescriptiveMetadata->cloneNode(true);
                $newNode->setAttribute('xml:lang', $language);
                $domDoc->documentElement->insertBefore($newNode, $defaultAdministrativeMetadata);
            }

            // Clone the administrative metadata element if it is not present yet for this language
            $query = $this->buildXpath('administrativeMetadata[@xml:lang="{language}"]', $namespace, $language);
            $administrativeMetadatas = $xpath->query($query);
            $makeNew = false;
            if(!$administrativeMetadatas) {
                $makeNew = true;
            } else if($administrativeMetadatas->length == 0) {
                $makeNew = true;
            }
            if($makeNew) {
                $administrativeMetadata = $defaultAdministrativeMetadata->cloneNode(true);
                $administrativeMetadata->setAttribute('xml:lang', $language);
                $domDoc->documentElement->appendChild($administrativeMetadata);
            }

            foreach ($dataDef as $key => $value) {

                $query = $this->buildXpath($value['xpath'], $namespace, $language);
                $domNodes = $xpath->query($query);
                if ($domNodes) {
                    if (array_key_exists('term', $value)) {
                        // Remove all child nodes and replace with a new one containing the correctly translated data

                        $children = explode('/', $value['term']);
                        foreach ($domNodes as $domNode) {
                            $childNodes = $domNode->childNodes;

                            // Remove all relevant child nodes
                            foreach ($childNodes as $childNode) {
                                if ($childNode->nodeName == $namespace . ':' . $children[0]) {
                                    $domNode->removeChild($childNode);
                                }
                            }

                            $newValue = null;
                            // Find the correct value for this work PID
                            foreach ($translations[$key] as $translation) {
                                if ($translation['Work PID'] == $workPid) {
                                    $newValue = $translation[$language];
                                }
                            }
                            if($newValue == null)
                                continue;
                            if(empty($newValue))
                                continue;


                            // Determine order in which the new node needs to be inserted
                            $objectIdentificationOrder = $this->getContainer()->getParameter('object_identification_order');

                            // The array that determines which elements the new element should precede
                            $before = array();
                            $encountered = false;
                            for ($i = 0; $i < count($objectIdentificationOrder); $i++) {
                                if ($objectIdentificationOrder[$i] == $children[0]) {
                                    $encountered = true;
                                } else if ($encountered) {
                                    $before[] = $namespace . ':' . $objectIdentificationOrder[$i];
                                }
                            }

                            // Insert new child node in the right place by searching for any elements that come after it
                            $newEle = $domDoc->createElement($namespace . ':' . $children[0]);
                            $added = false;
                            if (count($before) > 0) {
                                // Loop through all child nodes to find the right index where to insert it
                                foreach ($childNodes as $childNode) {
                                    $insert = false;
                                    foreach ($before as $bef) {
                                        if ($childNode->nodeName == $bef) {
                                            $insert = true;
                                            break;
                                        }
                                    }
                                    if ($insert) {
                                        $domNode->insertBefore($newEle, $childNode);
                                        $added = true;
                                    }
                                }
                            }

                            // Add at the end if the element should be last or if no elements succeeding this element exist
                            if (!$added) {
                                $domNode->appendChild($newEle);
                            }

                            // Recursively create this node's children
                            $domNode = $newEle;
                            for ($i = 1; $i < count($children); $i++) {
                                $newEle = $domDoc->createElement($namespace . ':' . $children[$i]);
                                $domNode->appendChild($newEle);
                                $domNode = $newEle;
                            }

                            // Set the correct value
                            $domNode->nodeValue = $newValue;

                            // Set attributes if applicable
                            if(array_key_exists('attributes', $value)) {
                                foreach ($value['attributes'] as $key => $value) {
                                    $domNode->setAttribute($key, str_replace('{language}', $language, $value));
                                }
                            }
                        }
                    } else {
                        // Translate all occurrences
                        foreach ($domNodes as $domNode) {
                            $found = false;
                            foreach ($translations[$key] as $translation) {
                                if($found) break;

                                foreach($translation as $trans) {
                                    if ($trans == $domNode->nodeValue) {

                                        // Change language attribute if present
                                        $oldLang = $domNode->getAttribute('xml:lang');
                                        if($oldLang) {
                                            if($oldLang != null && !empty($oldLang)) {
                                                $domNode->setAttribute('xml:lang', $language);
                                            }
                                        }

                                        // Set translated value
                                        $domNode->nodeValue = $translation[$language];

                                        $found = true;
                                        break;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        return $domDoc->saveXML();
    }

    // Build the xpath based on the provided namespace
    private function buildXpath($xpath, $namespace, $language)
    {
        $xpath = str_replace('{language}', $language, $xpath);
        $xpath = str_replace('[@', '[@' . $namespace . ':', $xpath);
        $xpath = str_replace('[@' . $namespace . ':xml:', '[@xml:', $xpath);
        $xpath = preg_replace('/\[([^@])/', '[' . $namespace . ':${1}', $xpath);
        $xpath = preg_replace('/\/([^\/])/', '/' . $namespace . ':${1}', $xpath);
        if(strpos($xpath, '/') !== 0) {
            $xpath = $namespace . ':' . $xpath;
        }
        $xpath = 'descendant::' . $xpath;
        return $xpath;
    }

    private function addRelatedWorksWrap($namespace, $recordInfo, $workPid, $isPartsOfLine, $hasPartsLine, $domDoc, $defaultDescriptiveMetadata)
    {
        $relatedWorksWrap = null;
        if(!empty($isPartsOfLine) || !empty($hasPartsLine)) {
            $objectRelationWrap = $domDoc->createElement($namespace . ':objectRelationWrap');
            $defaultDescriptiveMetadata->appendChild($objectRelationWrap);
            $relatedWorksWrap = $domDoc->createElement($namespace . ':relatedWorksWrap');
            $objectRelationWrap->appendChild($relatedWorksWrap);
        }

        if(!empty($isPartsOfLine)) {
            $this->addPartsOf($namespace, $recordInfo, $workPid, $isPartsOfLine, $domDoc, $relatedWorksWrap);
        }

        if(!empty($hasPartsLine)) {
            $this->addHasParts($namespace, $recordInfo, $workPid, $hasPartsLine, $domDoc, $relatedWorksWrap);
        }
    }

    private function addPartsOf($namespace, $recordInfo, $workPid, $isPartsOfLine, $domDoc, $relatedWorksWrap)
    {
        $isPartsOf = explode(' ; ', $isPartsOfLine);
        foreach($isPartsOf as $isPartOf) {
            $dataPid = null;
            foreach($recordInfo as $otherRecord) {
                if($otherRecord['workPid'] == $isPartOf) {
                    $dataPid = $otherRecord['dataPid'];
                    break;
                }
            }
            if($dataPid == null) {
                echo 'Error: parent of ' . $workPid . ' with work PID ' . $isPartOf . ' not found!' . PHP_EOL;
            } else {
                $relatedWorkSet = $domDoc->createElement($namespace . ':relatedWorkSet');
                $relatedWorksWrap->appendChild($relatedWorkSet);
                $relatedWork = $domDoc->createElement($namespace . ':relatedWork');
                $relatedWorkSet->appendChild($relatedWork);
                $object = $domDoc->createElement($namespace . ':object');
                $relatedWork->appendChild($object);
                $objectID = $domDoc->createElement($namespace . ':objectID');
                $expl = explode(':', $dataPid);
                $objectID->setAttribute($namespace . ':source', '');
                $objectID->setAttribute($namespace . ':type', 'local');
                $objectID->nodeValue = $expl[count($expl) - 1];
                $object->appendChild($objectID);
                $objectID = $domDoc->createElement($namespace . ':objectID');
                $objectID->setAttribute($namespace . ':type', 'oai');
                $objectID->nodeValue = $dataPid;
                $object->appendChild($objectID);
                $relatedWorkRelType = $domDoc->createElement($namespace . ':relatedWorkRelType');
                $relatedWorkSet->appendChild($relatedWorkRelType);
                $conceptId = $domDoc->createElement($namespace . ':conceptID');
                $conceptId->setAttribute($namespace . ':type', 'URI');
                // Hardcoded value
                $conceptId->nodeValue = 'http://purl.org/dc/terms/isPartOf';
                $relatedWorkRelType->appendChild($conceptId);
                $term = $domDoc->createElement($namespace . ':term');
                $term->setAttribute('xml:lang', 'en');
                // Hardcoded value
                $term->nodeValue = 'Is Part Of';
                $relatedWorkRelType->appendChild($term);
            }
        }
    }

    private function addHasParts($namespace, $recordInfo, $workPid, $hasPartsLine, $domDoc, $relatedWorksWrap)
    {
        $hasParts = explode(' ; ', $hasPartsLine);
        foreach($hasParts as $hasPart) {
            $dataPid = null;
            foreach($recordInfo as $otherRecord) {
                if($otherRecord['workPid'] == $hasPart) {
                    $dataPid = $otherRecord['dataPid'];
                    break;
                }
            }
            if($dataPid == null) {
                echo 'Error: child of ' . $workPid . ' with work PID ' . $hasPart . ' not found!' . PHP_EOL;
            } else {
                $relatedWorkSet = $domDoc->createElement($namespace . ':relatedWorkSet');
                $relatedWorksWrap->appendChild($relatedWorkSet);
                $relatedWork = $domDoc->createElement($namespace . ':relatedWork');
                $relatedWorkSet->appendChild($relatedWork);
                $object = $domDoc->createElement($namespace . ':object');
                $relatedWork->appendChild($object);
                $objectID = $domDoc->createElement($namespace . ':objectID');
                $expl = explode(':', $dataPid);
                $objectID->setAttribute($namespace . ':source', '');
                $objectID->setAttribute($namespace . ':type', 'local');
                $objectID->nodeValue = $expl[count($expl) - 1];
                $object->appendChild($objectID);
                $objectID = $domDoc->createElement($namespace . ':objectID');
                $objectID->setAttribute($namespace . ':type', 'oai');
                $objectID->nodeValue = $dataPid;
                $object->appendChild($objectID);
                $relatedWorkRelType = $domDoc->createElement($namespace . ':relatedWorkRelType');
                $relatedWorkSet->appendChild($relatedWorkRelType);
                $conceptId = $domDoc->createElement($namespace . ':conceptID');
                $conceptId->setAttribute($namespace . ':type', 'URI');
                // Hardcoded value
                $conceptId->nodeValue = 'http://purl.org/dc/terms/hasPart';
                $relatedWorkRelType->appendChild($conceptId);
                $term = $domDoc->createElement($namespace . ':term');
                $term->setAttribute('xml:lang', 'en');
                // Hardcoded value
                $term->nodeValue = 'Has Part';
                $relatedWorkRelType->appendChild($term);
            }
        }
    }

    private function addPhotosAndCopyright($namespace, $rightsStatusesLine, $photoIdsLine, $domDoc, $defaultAdministrativeMetadata)
    {
        $rightsStatuses = explode(' ; ', $rightsStatusesLine);
        $photoIds = explode(' ; ', $photoIdsLine);
        if(count($rightsStatuses) != count($photoIds)) {
            echo 'Error: copyright status count (' . count($rightsStatuses) . ') and photo id count (' . count($photoIds) . ') do not match!' . PHP_EOL;
            return $domDoc;
        }

        // Add photo id('s) and copyright status(es) to the administrative metadata
        for($i = 0; $i < count($rightsStatuses); $i++) {
            $resourceSet = $domDoc->createElement($namespace . ':resourceSet');
            $defaultAdministrativeMetadata->appendChild($resourceSet);
            $resourceId = $domDoc->createElement($namespace . ':resourceID');
            $resourceId->setAttribute($namespace . ':type', 'local');
            $resourceId->nodeValue = $photoIds[$i];
            $resourceSet->appendChild($resourceId);
            $resourceSource = $domDoc->createElement($namespace . ':resourceSource');
            $resourceSource->setAttribute($namespace . ':type', 'holder of image');
            $resourceSet->appendChild($resourceSource);
            $legalBodyName = $domDoc->createElement($namespace . ':legalBodyName');
            $resourceSource->appendChild($legalBodyName);
            $appellationValue = $domDoc->createElement($namespace . ':appellationValue');
            // Hardcoded value
            $appellationValue->nodeValue = 'Lukas, Arts in Flanders';
            $legalBodyName->appendChild($appellationValue);
            $rightsResource = $domDoc->createElement($namespace . ':rightsResource');
            $resourceSet->appendChild($rightsResource);
            $rightsType = $domDoc->createElement($namespace . ':rightsType');
            $rightsResource->appendChild($rightsType);
            $conceptId = $domDoc->createElement($namespace . ':conceptID');
            $conceptId->setAttribute($namespace . ':type', 'URI');
            $conceptId->nodeValue = $rightsStatuses[$i];
            $term = $domDoc->createElement($namespace . ':term');
            // Three possible hardcoded values
            switch($rightsStatuses[$i]) {
                case "https://creativecommons.org/publicdomain/zero/1.0/":
                    $conceptId->setAttribute($namespace . ':source', 'Creative Commons');
                    $term->nodeValue = 'CC0';
                    break;
                case "http://rightsstatements.org/vocab/InC/1.0/":
                    $conceptId->setAttribute($namespace . ':source', 'rightsstatements.org');
                    $term->nodeValue = 'InC';
                    break;
                case "http://rightsstatements.org/vocab/CNE/1.0/":
                    $conceptId->setAttribute($namespace . ':source', 'rightsstatements.org');
                    $term->nodeValue = 'CNE';
                    break;
                default:
                    echo 'Error: invalid copyright status "' . $rightsStatuses[$i] . '"' . PHP_EOL;
                    break;
            }
            $rightsType->appendChild($conceptId);
            $rightsType->appendChild($term);
        }
    }
}
