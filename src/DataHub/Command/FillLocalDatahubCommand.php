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
use function GuzzleHttp\Psr7\str;
use Phpoaipmh\Endpoint;
use Phpoaipmh\Exception\OaipmhException;
use SebastianBergmann\CodeCoverage\Report\PHP;
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

        // Grab the record ID's we need to fetch from the remote Datahub
        $csvFolder = $this->getContainer()->getParameter('csv_folder');
        $recordInfo = $this->readRecordIdsFromCsv($csvFolder . $this->getContainer()->getParameter('record_ids_csv_file'));

        // Fetch all translations of all the fields to alter the lido data
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
                $datUrn = $record['Data URN'];
                $rec = $myEndpoint->getRecord($datUrn, $metadataPrefix);

                $data = $rec->GetRecord->record->metadata->children($namespace, true);

                //Fetch the data from this record based on data_definition in data_import.yml
                $newData = $this->alterData($dataDef, $namespace, $data, $record['Work PID'], $languages, $translations);

                $doc = new \DOMDocument();
                $doc->formatOutput = true;
                $doc->loadXML($newData);

                $doc->getElementsByTagName("lidoRecID")->item(0)->nodeValue = $datUrn;
                $raw = $doc->saveXML();

                $record = new Record();
                $record->setRecordIds(array($datUrn));
                $record->setObjectIds(array());
                $record->setRaw($raw);

                $manager = $this->getContainer()->get('doctrine_mongodb')->getManager();
                $manager->persist($record);
            }
            catch(OaipmhException $e) {
                echo $e->getMessage() . PHP_EOL;
            }
        }
        $manager->flush();
    }

    private function readRecordIdsFromCsv($csvFile)
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
    private function alterData($dataDef, $namespace, $data, $workPid, $languages, $translations)
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
                $newNode = $defaultAdministrativeMetadata->cloneNode(true);
                $newNode->setAttribute('xml:lang', $language);
                $domDoc->documentElement->appendChild($newNode);
            }

            foreach ($dataDef as $key => $value) {

                $query = $this->buildXpath($value['xpath'], $namespace, $language);
                $domNodes = $xpath->query($query);
                if ($domNodes) {
                    if (array_key_exists('term', $value)) {
                        // Remove all child nodes and replace with one new one

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
}
