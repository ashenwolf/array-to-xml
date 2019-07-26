<?php

namespace Ashenwolf\ArrayToXml;

use Exception;
use XMLWriter;
use DOMException;

class ArrayToXmlFile
{
    protected $writer;

    protected $data;

    protected $rootElement;

    protected $xmlEncoding;

    protected $xmlVersion;

    protected $inMemory;

    protected $replaceSpacesByUnderScoresInKeyNames = true;

    protected $numericTagNamePrefix = 'numeric_';

    public function __construct(
        array $array,
        string $outputUri = null,
        $rootElement = '',
        bool $replaceSpacesByUnderScoresInKeyNames = true,
        string $xmlEncoding = null,
        string $xmlVersion = '1.0',
        int $nodesToFlush = 1000
    ) {
        $this->writer = new XmlWriter();

        $this->replaceSpacesByUnderScoresInKeyNames = $replaceSpacesByUnderScoresInKeyNames;

        if ($this->isArrayAllKeySequential($array) && ! empty($array)) {
            throw new DOMException('Invalid Character Error');
        }

        if ($outputUri) {
            $this->writer->openUri($outputUri);
            $this->inMemory = false;
        }
        else {
            $this->writer->openMemory();
            $this->inMemory = true;
        }

        $this->writer->setIndent(true);

        $this->data = $array;
        $this->rootElement = $rootElement;
        $this->xmlEncoding = $xmlEncoding;
        $this->xmlVersion = $xmlVersion;
        $this->nodesToFlush = $nodesToFlush;
        $this->nodesCount = 0;
    }

    public function setNumericTagNamePrefix(string $prefix)
    {
        $this->numericTagNamePrefix = $prefix;
    }

    public static function convert(
        array $array,
        string $outputUri = null,
        $rootElement = '',
        bool $replaceSpacesByUnderScoresInKeyNames = true,
        string $xmlEncoding = null,
        string $xmlVersion = '1.0',
        int $nodesToFlush = 1000
    ) {
        $converter = new static(
            $array,
            $outputUri,
            $rootElement,
            $replaceSpacesByUnderScoresInKeyNames,
            $xmlEncoding,
            $xmlVersion
        );

        return $converter->toXml();
    }

    public function toXml(): string
    {
        $this->writer->startDocument($this->xmlVersion, $this->xmlEncoding);

        $this->createRootElement($this->rootElement, function() {
            $this->convertElement($this->data);
        });

        $this->writer->endDocument();

        $result =  $this->inMemory ? $this->writer->outputMemory() : $this->writer->flush();

        unset($this->writer);

        return $result;
    }

    private function convertElement($value, $siblingKey = null)
    {
        $sequential = $this->isArrayAllKeySequential($value);

        if (!is_array($value)) {
            $value = $value;

            if (!is_null($value)) {
                $value = $this->removeControlCharacters($value);

                $this->writer->text($value);    
            }

            return;
        }

        if (!$sequential) {
            if ($value['_attributes'] ?? false) $this->addAttributes($value['_attributes']);
            if ($value['@attributes'] ?? false) $this->addAttributes($value['@attributes']);

            $nodeValues = array_filter($value, function ($key) { return !in_array($key, ['_attributes', '@attributes']); }, ARRAY_FILTER_USE_KEY);
            foreach ($nodeValues as $key => $data) {
                if ((($key === '_value') || ($key === '@value')) && is_string($data)) {
                    $this->writer->text($value[$key]);
                } elseif ((($key === '_cdata') || ($key === '@cdata')) && is_string($data)) {
                    $this->writer->writeCdata($value[$key]);
                } elseif ((($key === '_mixed') || ($key === '@mixed')) && is_string($data)) {
                    $this->writer->writeRaw($value[$key]);
                } elseif ($key === '__numeric') {
                    $this->addNumericNode($data);
                } else {
                    $this->addNode($key, $data);
                }
            }
        } else {
            foreach ($value as $key => $data) {
                if (is_array($data)) {
                    $this->addCollectionNode($data, $siblingKey);
                }
                else {
                    $this->addSequentialNode($data, $siblingKey);
                }
            }
        }
    }

    protected function addNumericNode($value)
    {
        foreach ($value as $key => $item) {
            $this->convertElement([$this->numericTagNamePrefix.$key => $value]);
        }
    }

    protected function addNode($key, $value)
    {
        if ($this->replaceSpacesByUnderScoresInKeyNames) {
            $key = str_replace(' ', '_', $key);
        }

        $sequential = $this->isArrayAllKeySequential($value);
        if (!$sequential) $this->startElement($key);
        $this->convertElement($value, $key);
        if (!$sequential) $this->endElement();
    }

    protected function addCollectionNode($value, $siblingKey)
    {
        $this->startElement($siblingKey);
        $this->convertElement($value);
        $this->endElement();
    }

    protected function addSequentialNode($value, $siblingKey)
    {
        $this->startElement($siblingKey);
        $this->writer->text(htmlspecialchars($value));
        $this->endElement();
    }

    protected function isArrayAllKeySequential($value)
    {
        if (! is_array($value)) {
            return false;
        }

        if (count($value) <= 0) {
            return true;
        }

        if (\key($value) === '__numeric') {
            return false;
        }

        return array_unique(array_map('is_int', array_keys($value))) === [true];
    }

    protected function addAttributes(array $data)
    {
        foreach ($data as $attrKey => $attrVal) {
            $this->writer->writeAttribute($attrKey, $attrVal);
        }
    }

    protected function createRootElement($rootElement, $innerDocument)
    {
        $rootElementName = 
            is_string($rootElement) ? ($rootElement ?: 'root') : ($rootElement['rootElementName'] ?? 'root');

        $this->startElement($rootElementName);

        if (is_array($rootElement)) {
            $this->addAttributes($rootElement["_attributes"] ?? []);
            $this->addAttributes($rootElement["@attributes"] ?? []);
        }

        $innerDocument();
                
        $this->endElement();
    }

    protected function removeControlCharacters(string $value): string
    {
        return preg_replace('/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F]/', '', $value);
    }

    protected function endElement()
    {
        $this->writer->endElement();
        if (!$this->inMemory && $this->nodesCount++ > $this->nodesToFlush)
        {
            $this->writer->flush();
        }
    }

    protected function startElement($name)
    {
        try {
            $result = $this->writer->startElement($name);
        } catch (exception $e) {
            throw new DOMException("'{$name}': {$e->getMessage()}");
        }
        if (!$result)
            throw new DOMException(error_get_last());
    }
}
