<?php

/**
 * This file is part of the PHP Oxygen package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @copyright   Copyright (c) 2011 Sébastien HEYD <sheyd@php-oxygen.com>
 * @author      Sébastien HEYD <sheyd@php-oxygen.com>
 * @package     PHP Oxygen
 */

class f_i18n_Xliff
{
    private static $_instances;
    
    /* @var $_xml SimpleXMLElement */
    private $_xml;
    private $_file;
    
    /**
     * Main constructor
     * 
     * @param string $file  XLIFF file to use
     */
    private function __construct($file)
    {
        $this->_file = $file;
        if(file_exists($file))
        {    
            $this->_xml = simplexml_load_file($file);
            $namespaces = $this->_xml->getDocNamespaces();
            $this->_xml->registerXPathNamespace('ns', $namespaces['']);
        }

        if(!is_dir(dirname($this->_file)))
        {
            mkdir(dirname($this->_file));
        }
    }
    
    /**
     * Get an instance of f_i18n_Xliff
     * 
     * @param string $file      XLIFF file to use
     * @return f_i18n_Xliff     Instance of f_i18n_Xliff (multiton)
     */
    public static function getInstance($file)
    {
        if(!isset(self::$_instances[$file]))
        {
            self::$_instances[$file] = new self($file);
        }        
        return self::$_instances[$file];
    }       
    
    /**
     * Translate the given string to the current i18n locale language.
     * 
     * @param string $string        The string to translate
     * @param array $args           [optional] Associative array of elements to replace in the given string.<br />Exemple : translate('My name is %name%', array('name' => 'Jim'))
     * @param string $srcLang       [optional] ISO 639-1 code of the source language. Default is 'en'
     * @param string $origin        [optional] The name of the original file where is located the given string. Default is 'default'
     * @param boolean $addToFile    [optional] If not found with the current language, add to xliff file ?
     * @return string               The translated string if found else the source string
     */
    public function translate($string, $args = array(), $srcLang = 'en', $origin = 'default', $addToFile = true)
    {        
        if(is_null($this->_xml)) $this->_createFile($string, $srcLang, $origin);
        
        $t = $this->_xml->xpath('//ns:file[@source-language="'.$srcLang.'"][@original="'.$origin.'"]//ns:trans-unit/ns:source[.="'.$string.'"]/../ns:target');                   
            
        if(empty($t))
        {
            $t = $this->_xml->xpath('//ns:file[@source-language="'.$srcLang.'"]//ns:trans-unit/ns:source[.="'.$string.'"]/../ns:target');
            $target = !empty($t) ? (string) end($t) : null;
            
            if($addToFile)
            {
                // add to file
                $this->_addToFile($string, $srcLang, $origin, $target);         
            }
            
            $res = !is_null($target) ? $target : $string;                
        }    
        else
        {
            $res = (string) end($t);
        }
        
        if($res == '') $res = $string;

        if(!empty($args))
        {
            foreach($args as $k => $v)
            {
                $res = str_replace("%$k%", $v, $res);
            }
        }

        return $res;             
    }
    
    /**
     * Add a new translation to an existing XLIFF file
     * 
     * @param string $string    The string to add into <source> node
     * @param string $srcLang   ISO 639-1 code of the source language
     * @param string $origin    The name of the original file where is located the given string
     * @param string $target    [optional] Set the target content. Default is null
     */
    private function _addToFile($string, $srcLang, $origin, $target = null)
    {
        // instanciate a new DOM document
        $doc = new DOMDocument();
        
        // keep format and indentation
        $doc->formatOutput = true;
        $doc->preserveWhiteSpace = false;
        
        // load the file content
        $doc->loadXML(file_get_contents($this->_file));
        
        // begin a new xpath and register defaut namespace
        $xpath = new DOMXpath($doc);
        $xpath->registerNamespace('ns', 'urn:oasis:names:tc:xliff:document:1.2');
        
        // first query, check if there is already a source item for the given string
        $check = $xpath->query('//ns:file[@source-language="'.$srcLang.'"][@original="'.$origin.'"]//ns:trans-unit/ns:source[.="'.$string.'"]');      
        if($check->length > 0) return;
        
        // second query, get body for the given language and origin
        $bodies = $xpath->query('//ns:file[@source-language="'.$srcLang.'"][@original="'.$origin.'"]/ns:body');                
        $body = $bodies->length > 0 ? $bodies->item(0) : null;  

        // third query, get all trans-units for the given language and origin
        $elements = $xpath->query('//ns:file[@source-language="'.$srcLang.'"][@original="'.$origin.'"]//ns:trans-unit');                 
        $nextId = $elements->length + 1;       

        // create source node
        $source = $doc->createElement('source');  
        
        if(preg_match('/[<&]/i', $string))
        {
            $sourceText = $doc->createCDATASection($string);
        }
        else
        {
            $sourceText = $doc->createTextNode($string);            
        }
        $source->appendChild($sourceText);
        
        // create target node
        $targetNode = $doc->createElement('target');        
        $targetText = $doc->createTextNode(!is_null($target) ? $target : '');
        $targetNode->appendChild($targetText);
        
        // create trans-unit node
        $transUnit = $doc->createElement('trans-unit');
        $transUnit->setAttribute('id', $nextId);
        $transUnit->appendChild($source);
        $transUnit->appendChild($targetNode);
        
        // there is already a body node
        if(!is_null($body))
        {
            // add the new trans-unit node
            $body->appendChild($transUnit);
        }
        else
        {
            // create a new body node
            $body = $doc->createElement('body');
            $body->appendChild($transUnit);
            
            // create file node
            $file = $doc->createElement('file');
            $file->setAttribute('source-language', $srcLang);
            $file->setAttribute('datatype', 'plaintext');
            $file->setAttribute('original', $origin);
            $file->appendChild($body);
            
            // add to the existent xliff node
            $xliffs = $xpath->query('/ns:xliff');   
            $xliffs->item(0)->appendChild($file);
        }
        
        // get XML content
        $xml = $doc->saveXML();
        
        // replace auto-closed tags
        $xml = str_replace('<target/>', '<target></target>', $xml);
        
        // write into the file
        file_put_contents($this->_file, $xml);
    }        
    
    /**
     * Create and write a new XLIFF file
     * 
     * @param string $string    The string to add into <source> node
     * @param string $srcLang   ISO 639-1 code of the source language
     * @param string $origin    The name of the original file where is located the given string
     */
    private function _createFile($string, $srcLang, $origin)
    {
        // instantiate a new XML writer
        $xml = new XMLWriter();
        
        // put content in memory and set options
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->setIndentString('    ');
        
        // start a new UTF-8 XML document
        $xml->startDocument('1.0','UTF-8');
        
            // xliff node
            $xml->startElement('xliff');
            $xml->writeAttribute('version', '1.2');
            $xml->writeAttribute('xmlns', 'urn:oasis:names:tc:xliff:document:1.2');
            
                // file node
                $xml->startElement('file');
                $xml->writeAttribute('source-language', $srcLang);
                $xml->writeAttribute('datatype', 'plaintext');
                $xml->writeAttribute('original', $origin);
                
                    // body node
                    $xml->startElement('body');
                    
                        // trans-unit node
                        $xml->startElement('trans-unit');
                        $xml->writeAttribute('id', '1');
                        
                            // source node
                            $xml->startElement('source');
                                $xml->writeRaw($string);
                            $xml->endElement();
                            
                            // target node
                            $xml->startElement('target');
                                $xml->text('');
                            $xml->endElement();
                        
                        // end trans-unit
                        $xml->endElement();
                        
                    // end body    
                    $xml->endElement();
                    
               // end file     
                $xml->endElement();                
                
            // end xliff    
            $xml->endElement();
            
        // end document    
        $xml->endDocument();
        
        // get content and put contents into the file
        $result = $xml->outputMemory(true);
        file_put_contents($this->_file, $result);
        
        // instantiate the generated xml file
        $this->__construct($this->_file);
    }
}