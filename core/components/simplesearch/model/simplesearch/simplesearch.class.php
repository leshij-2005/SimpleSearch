<?php
/**
 * SimpleSearch
 *
 * Copyright 2010 by Shaun McCormick <shaun@modxcms.com>
 *
 * This file is part of SimpleSearch, a simple search component for MODx
 * Revolution. It is loosely based off of AjaxSearch for MODx Evolution by
 * coroico/kylej, minus the ajax.
 *
 * SimpleSearch is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License as published by the Free Software
 * Foundation; either version 2 of the License, or (at your option) any later
 * version.
 *
 * SimpleSearch is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS
 * FOR A PARTICULAR PURPOSE. See the GNU General Public License for more
 * details.
 *
 * You should have received a copy of the GNU General Public License along with
 * SimpleSearch; if not, write to the Free Software Foundation, Inc., 59 Temple Place,
 * Suite 330, Boston, MA 02111-1307 USA
 *
 * @package simplesearch
 */
/**
 * The base class for SimpleSearch
 *
 * @package
 */
class SimpleSearch {
    public $modx;
    public $config = array();
    public $searchString = '';
    public $searchArray = array();
    public $ids = '';
    public $docs = array();
    public $searchScores = array();

    function __construct(modX &$modx,array $config = array()) {
    	$this->modx =& $modx;
        $corePath = $this->modx->getOption('sisea.core_path',null,$this->modx->getOption('core_path').'components/simplesearch/');
        $assetsUrl = $this->modx->getOption('sisea.assets_url',null,$this->modx->getOption('assets_url').'components/simplesearch/');

        $this->config = array_merge(array(
            'corePath' => $corePath,
            'chunksPath' => $corePath.'elements/chunks/',
            'snippetsPath' => $corePath.'elements/snippets/',
            'modelPath' => $corePath.'model/',
        ),$config);
        $this->modx->lexicon->load('sisea:default');
    }

    /**
     * Gets a Chunk and caches it; also falls back to file-based templates
     * for easier debugging.
     *
     * @access public
     * @param string $name The name of the Chunk
     * @param array $properties The properties for the Chunk
     * @return string The processed content of the Chunk
     */
    public function getChunk($name,$properties = array()) {
        $chunk = null;
        if (!isset($this->chunks[$name])) {
            $chunk = $this->_getTplChunk($name);
            if (empty($chunk)) {
                $chunk = $this->modx->getObject('modChunk',array('name' => $name),true);
                if ($chunk == false) return false;
            }		
            $this->chunks[$name] = $chunk->getContent();
        } else {
            $o = $this->chunks[$name];
            $chunk = $this->modx->newObject('modChunk');
            $chunk->setContent($o);
        }
        $chunk->setCacheable(false);
        return $chunk->process($properties);
    }

    /**
     * Returns a modChunk object from a template file.
     *
     * @access private
     * @param string $name The name of the Chunk. Will parse to name.chunk.tpl
     * @return modChunk/boolean Returns the modChunk object if found, otherwise
     * false.
     */
    private function _getTplChunk($name) {
        $chunk = false;
        $f = $this->config['chunksPath'].strtolower($name).'.chunk.tpl';
        if (file_exists($f)) {
            $o = file_get_contents($f);
            $chunk = $this->modx->newObject('modChunk');
            $chunk->set('name',$name);
            $chunk->setContent($o);
        }
        return $chunk;
    }

    /**
     * Parses search string and removes any potential security risks in the search string
     *
     * @param string $str The string to parse.
     * @return string The parsed and cleansed string.
     */
    public function parseSearchString($str = '') {
        $minChars = $this->modx->getOption('minChars',$this->config,4);

        $this->searchArray = explode(' ',$str);
        $this->searchArray = $this->modx->sanitize($this->searchArray, $this->modx->sanitizePatterns);
        foreach ($this->searchArray as $key => $term) {
            $this->searchArray[$key] = strip_tags($term);
            if (strlen($term) < $minChars) unset($this->searchArray[$key]);
        }
        $this->searchString = implode(' ', $this->searchArray);
        return $this->searchString;
    }

    /**
     * Gets a modResource collection that matches the search terms
     *
     * @param string $str The string to use to search with.
     * @param array $scriptProperties
     * @return array An array of modResource results of the search.
     */
    public function getSearchResults($str = '',array $scriptProperties = array()) {
        if (!empty($str)) $this->searchString = strip_tags($this->modx->sanitizeString($str));

        $ids = $this->modx->getOption('ids',$scriptProperties,'');
        $exclude = $this->modx->getOption('exclude',$scriptProperties,'');
        $useAllWords = $this->modx->getOption('useAllWords',$scriptProperties,false);
        $searchStyle = $this->modx->getOption('searchStyle',$scriptProperties,'partial');
        $hideMenu = $this->modx->getOption('hideMenu',$scriptProperties,2);
        $maxWords = $this->modx->getOption('maxWords',$scriptProperties,7);
        $andTerms = $this->modx->getOption('andTerms',$scriptProperties,true);
        $matchWildcard = $this->modx->getOption('matchWildcard',$scriptProperties,true);
        $docFields = explode(',',$this->modx->getOption('docFields',$scriptProperties,'pagetitle,longtitle,alias,description,introtext,content'));

    	$c = $this->modx->newQuery('modResource');
        $c->leftJoin('modTemplateVarResource','TemplateVarResources');

        /* if using customPackages, add here */
        $customPackages = array();
        if (!empty($scriptProperties['customPackages'])) {
            $packages = explode('||',$scriptProperties['customPackages']);
            if (is_array($packages) && !empty($packages)) {
                $searchArray = array(
                    '{core_path}',
                    '{assets_path}',
                    '{base_path}',
                );
                $replacePaths = array(
                    $this->modx->getOption('core_path',null,MODX_CORE_PATH),
                    $this->modx->getOption('assets_path',null,MODX_ASSETS_PATH),
                    $this->modx->getOption('base_path',null,MODX_BASE_PATH),
                );
                foreach ($packages as $package) {
                    /* 0: class name, 1: field name(s) (csl), 2: package name, 3: package path, 4: criteria */
                    $package = explode(':',$package);
                    if (!empty($package[4])) {
                        $package[3] = str_replace($searchArray, $replacePaths, $package[3]);
                        $this->modx->addPackage($package[2],$package[3]);
                        $c->leftJoin($package[0],$package[0],$package[4]);
                        $customPackages[] = $package;
                    }
                }
            }
        }

    	/* process conditional clauses */
        $whereGroup=1;
        if ($searchStyle == 'partial' || $this->modx->config['dbtype'] == 'sqlsrv') {
            $wildcard = ($matchWildcard)? '%' : '';
            $whereArray = array();
            if (empty($useAllWords)) {
                $i = 1;
                foreach ($this->searchArray as $term) {
                    if ($i > $maxWords) break;
                    $term = $wildcard.$term.$wildcard;
                    foreach ($docFields as $field) {$whereArray[] = array($field.':LIKE', $term,xPDOQuery::SQL_OR,$whereGroup);}
                    $whereArray[] = array('TemplateVarResources.value:LIKE', $term, xPDOQuery::SQL_OR, $whereGroup);
                    if (is_array($customPackages) && !empty($customPackages)) {
                        foreach ($customPackages as $package) {
                            $fields = explode(',',$package[1]);
                            foreach ($fields as $field) {
                                $whereArray[] = array($package[0].'.'.$field.':LIKE', $term, xPDOQuery::SQL_OR, $whereGroup);
                            }
                        }
                    }
                    if ($andTerms) $whereGroup++;
                    $i++;
                }
            } else {
                $term = $wildcard.$this->searchString.$wildcard;
                foreach ($docFields as $field) {$whereArray[] = array($field.':LIKE', $term,xPDOQuery::SQL_OR,$whereGroup);}
                $whereArray[] = array('TemplateVarResources.value:LIKE', $term, xPDOQuery::SQL_OR, $whereGroup);
                if (is_array($customPackages) && !empty($customPackages)) {
                    foreach ($customPackages as $package) {
                        $fields = explode(',',$package[1]);
                        foreach ($fields as $field) {
                            $whereArray[] = array($package[0].'.'.$field.':LIKE', $term, xPDOQuery::SQL_OR, $whereGroup);
                        }
                    }
                }
            }
            $prevWhereGrp=0;
            foreach ($whereArray as $clause) {
                // The following works, but i consider it a hack, and should be fixed. -oori
                $c->where(array($clause[0] => $clause[1]), $clause[2] , null, $clause[3]);
                if ($clause[3] > $prevWhereGrp) $c->andCondition(array('AND:id:!=' => ''),null,$prevWhereGrp); // hack xpdo to prefix the whole thing with AND
                $prevWhereGrp = $clause[3];
            }
            $c->andCondition(array('AND:id:!=' => ''),null,$whereGroup-1); // xpdo hack: pad last condition...

    	} else {
            $fields = $this->modx->getSelectColumns('modResource', '', '', $docFields);
            if (is_array($customPackages) && !empty($customPackages)) {
                foreach ($customPackages as $package) {
                    $fields .= (!empty($fields) ? ',' : '').$this->modx->getSelectColumns($package[0],$package[0],'',explode(',',$package[1]));
                }
                $c->where($package[4]);
            }
            $wildcard = ($matchWildcard)? '*' : '';
            $relevancyTerms = array();
            if (empty($useAllWords)) {
                $i = 0;
                foreach ($this->searchArray as $term) {
                    if ($i > $maxWords) break;
                    $relevancyTerms[] = $this->modx->quote($term.$wildcard);
                    $i++;
                }
            } else {
                $relevancyTerms[] = $this->modx->quote($str.$wildcard);
            }
            $this->addRelevancyCondition($c, array(
                'class'=> 'modResource',
                'fields'=> $fields,
                'terms'=> $relevancyTerms
            ));
    	}
    	if (!empty($ids)) {
            $idType = $this->modx->getOption('idType',$this->config,'parents');
            $depth = $this->modx->getOption('depth',$this->config,10);
            $ids = $this->processIds($ids,$idType,$depth);
            $f = $this->modx->getSelectColumns('modResource','modResource','',array('id'));
            $c->where(array("{$f}:IN" => $ids),xPDOQuery::SQL_AND,null,$whereGroup);
        }
        if (!empty($exclude)) {
            $exclude = $this->cleanIds($exclude);
            $f = $this->modx->getSelectColumns('modResource','modResource','',array('id'));
            $c->where(array("{$f}:NOT IN" => explode(',', $exclude)),xPDOQuery::SQL_AND,null,2);
        }
    	$c->where(array('published:=' => 1), xPDOQuery::SQL_AND, null, $whereGroup);
    	$c->where(array('searchable:=' => 1), xPDOQuery::SQL_AND, null, $whereGroup);
    	$c->where(array('deleted:=' => 0), xPDOQuery::SQL_AND, null, $whereGroup);

        /* restrict to either this context or specified contexts */
        $ctx = !empty($this->config['contexts']) ? $this->config['contexts'] : $this->modx->context->get('key');
        $f = $this->modx->getSelectColumns('modResource','modResource','',array('context_key'));
    	$c->where(array("{$f}:IN" => explode(',', $ctx)), xPDOQuery::SQL_AND, null, $whereGroup);
        if ($hideMenu != 2) {
            $c->where(array('hidemenu' => $hideMenu == 1 ? true : false));
        }
        $this->searchResultsCount = $this->modx->getCount('modResource', $c);
        $c->query['distinct'] = 'DISTINCT';

    	/* set limit */
        $perPage = $this->modx->getOption('perPage',$this->config,10);
    	if (!empty($perPage)) {
            $offset = $this->modx->getOption('start',$this->config,0);
            $offsetIndex = $this->modx->getOption('offsetIndex',$this->config,'sisea_offset');
            if (isset($_REQUEST[$offsetIndex])) $offset = $_REQUEST[$offsetIndex];
            $c->limit($perPage,$offset);
    	}

        $this->docs = $this->modx->getCollection('modResource', $c);
        $this->sortResults($scriptProperties);
        return $this->docs;
    }

    public function addRelevancyCondition(&$query, Array $options) {}

    /**
     * Scores and sorts the results ($this->docs set by getSearchResults)
     * based on 'fieldPotency'
     *
     * @param $scriptProperties The $scriptProperties array
     * @return array Scored and sorted search results
     */
    protected function sortResults($scriptProperties) {
        // Vars
        $searchStyle = $this->modx->getOption('searchStyle', $scriptProperties, 'partial');
        $docFields = explode(',', $this->modx->getOption('docFields', $scriptProperties, 'pagetitle,longtitle,alias,description,introtext,content'));
        $fieldPotency = array_map('trim', explode(',', $this->modx->getOption('fieldPotency', $scriptProperties,'')));
        foreach ($fieldPotency as $key => $field) {
            unset($fieldPotency[$key]);
            $arr = explode(':', $field);
            $fieldPotency[$arr[0]] = $arr[1];
        }
        // Score
        foreach ($this->docs as $doc_id => $doc) {
            foreach ($docFields as $field) {
                $potency = (array_key_exists($field, $fieldPotency)) ? (int) $fieldPotency[$field] : 1;
                foreach ($this->searchArray as $term) {
                    $qterm = preg_quote($term);
                    $regex = ($searchStyle == 'partial') ? "/{$qterm}/i" : "/\b{$qterm}\b/i";
                    $n_matches = preg_match_all($regex, $doc->{$field}, $matches);
                    $this->searchScores[$doc_id] += $n_matches * $potency;
                }
            }
        }
        // Sort
        arsort($this->searchScores);
        $docs = array();
        foreach ($this->searchScores as $doc_id => $score) {
            array_push($docs, $this->docs[$doc_id]);
        }
        $this->docs = $docs;
        return $this->docs;
    }

    /**
     * Generates the pagination links
     *
     * @param integer $perPage The number of items per page
     * @param string $separator The separator to use between pagination links
     * @param bool|int $total The total of records. Will default to the main count if not passed
     * @return string Pagination links.
     */
    public function getPagination($perPage = 10,$separator = ' | ',$total = false) {
        if ($total === false) $total = $this->searchResultsCount;
        $pagination = '';

        /* setup default properties */
        $searchIndex = $this->modx->getOption('searchIndex',$this->config,'search');
        $searchOffset = $this->modx->getOption('offsetIndex',$this->config,'sisea_offset');
        $pageTpl = $this->modx->getOption('pageTpl',$this->config,'PageLink');
        $currentPageTpl = $this->modx->getOption('currentPageTpl',$this->config,'CurrentPageLink');
        $urlScheme = $this->modx->getOption('urlScheme',$this->config,-1);

        /* get search string */
        if (!empty($this->searchString)) {
            $searchString = $this->searchString;
        } else {
            $searchString = isset($_REQUEST[$searchIndex]) ? $_REQUEST[$searchIndex] : '';
        }

        $pageLinkCount = ceil($total / $perPage);
        $pageArray = array();
        $id = $this->modx->resource->get('id');
        for ($i = 0; $i < $pageLinkCount; ++$i) {
            $pageArray['text'] = $i+1;
            $pageArray['separator'] = $separator;
            $pageArray['offset'] = $i * $perPage;
            if ($_GET[$searchOffset] == $pageArray['offset']) {
                $pageArray['link'] = $i+1;
                $pagination .= $this->getChunk($currentPageTpl,$pageArray);
            } else {
                $parameters = $this->modx->request->getParameters();
                $parameters = array_merge($parameters,array(
                    $searchOffset => $pageArray['offset'],
                    $searchIndex => $searchString,
                ));
                $pageArray['link'] = $this->modx->makeUrl($id, $urlScheme,$parameters);
                $pagination .= $this->getChunk($pageTpl,$pageArray);
            }
            if ($i < $pageLinkCount) {
                $pagination .= $separator;
            }
        }
        return trim($pagination,$separator);
    }

    /**
     * Sanitize a string
     *
     * @param string $text The text to sanitize
     * @return string The sanitized text
     */
    public function sanitize($text) {
        $text = strip_tags($text);
        $text = preg_replace('/(\[\[\+.*?\]\])/i', '', $text);
        return $this->modx->stripTags($text);
    }

    /**
     * Create an extract from the passed text parameter
     *
     * @param string $text The text that the extract will be created from.
     * @param int $length The length of the extract to be generated.
     * @param string $search The search term to center the extract around.
     * @param string $ellipsis The ellipsis to use to wrap around the extract.
     * @return string The generated extract.
     */
    public function createExtract($text, $length = 200,$search = '',$ellipsis = '...') {
        $text = trim(preg_replace('/\s+/', ' ', $this->sanitize($text)));
        if (empty($text)) return '';

        $usemb = $this->modx->getOption('use_multibyte',null,false) && function_exists('mb_strlen');
        $encoding = $this->modx->getOption('modx_charset',null,'UTF-8');

        $trimchars = "\t\r\n -_()!~?=+/*\\,.:;\"'[]{}`&";
        if (empty($search)) {
            if ($usemb) {
                $pos = min(mb_strpos($text, ' ', $length - 1, $encoding), mb_strpos($text, '.', $length - 1, $encoding));
            } else {
                $pos = min(strpos($text, ' ', $length - 1), strpos($text, '.', $length - 1));
            }
            if ($pos) {
                return rtrim($usemb ? mb_substr($text,0,$pos,$encoding) : substr($text,0,$pos), $trimchars) . $ellipsis;
            } else {
                return $text;
            }
        }

        if ($usemb) {
            $wordpos = mb_strpos(mb_strtolower($text,$encoding), mb_strtolower($search,$encoding),null,$encoding);
            $halfside = intval($wordpos - $length / 2 + mb_strlen($search, $encoding) / 2);
            if ($halfside > 0) {
                $halftext = mb_substr($text, 0, $halfside, $encoding);
                $pos_start = min(mb_strrpos($halftext, ' ', 0, $encoding), mb_strrpos($halftext, '.', 0, $encoding));
                if (!$pos_start) {
                  $pos_start = 0;
                }
            } else {
                $pos_start = 0;
            }
            if ($wordpos && $halfside > 0) {
                $pos_end = min(mb_strpos($text, ' ', $pos_start + $length - 1, $encoding), mb_strpos($text, '.', $pos_start + $length - 1, $encoding)) - $pos_start;
                if (!$pos_end || $pos_end <= 0) {
                  $extract = $ellipsis . ltrim(mb_substr($text, $pos_start, mb_strlen($text, $encoding), $encoding), $trimchars);
                } else {
                  $extract = $ellipsis . trim(mb_substr($text, $pos_start, $pos_end, $encoding), $trimchars) . $ellipsis;
                }
            } else {
                $pos_end = min(mb_strpos($text, ' ', $length - 1, $encoding), mb_strpos($text, '.', $length - 1, $encoding));
                if ($pos_end) {
                  $extract = rtrim(mb_substr($text, 0, $pos_end, $encoding), $trimchars) . $ellipsis;
                } else {
                  $extract = $text;
                }
            }
        } else {
            $wordpos = strpos(strtolower($text), strtolower($search));
            $halfside = intval($wordpos - $length / 2 + strlen($search) / 2);
            if ($halfside > 0) {
                $halftext = substr($text, 0, $halfside);
                $pos_start = min(strrpos($halftext, ' '), strrpos($halftext, '.'));
                if (!$pos_start) {
                  $pos_start = 0;
                }
            } else {
                $pos_start = 0;
            }
            if ($wordpos && $halfside > 0) {
                $pos_end = min(strpos($text, ' ', $pos_start + $length - 1), strpos($text, '.', $pos_start + $length - 1)) - $pos_start;
                if (!$pos_end || $pos_end <= 0) {
                  $extract = $ellipsis . ltrim(substr($text, $pos_start), $trimchars);
                } else {
                  $extract = $ellipsis . trim(substr($text, $pos_start, $pos_end), $trimchars) . $ellipsis;
                }
            } else {
                $pos_end = min(strpos($text, ' ', $length - 1), strpos($text, '.', $length - 1));
                if ($pos_end) {
                  $extract = rtrim(substr($text, 0, $pos_end), $trimchars) . $ellipsis;
                } else {
                  $extract = $text;
                }
            }
        }
        return $extract;
    }


    /**
     * Adds highlighting to the passed string
     *
     * @param string $string The string to be highlighted.
     * @param string $cls The CSS class to add to the tag wrapper
     * @param string $tag The type of HTML tag to wrap with
     * @return string The highlighted string
     */
    public function addHighlighting($string, $cls = 'sisea-highlight',$tag = 'span') {
        if (is_array($this->searchArray)) {
            foreach ($this->searchArray as $key => $value) {
                $string = preg_replace('/' . $value . '/i', '<'.$tag.' class="'.$cls.' '.$cls.'-'.($key+1).'">$0</'.$tag.'>', $string);
            }
        }
        return $string;
    }

    /**
     * Process the passed IDs
     *
     * @param string $ids The IDs to search
     * @param string $type The type of id filter
     * @param integer $depth The depth in the Resource tree to filter by
     * @return string Comma delimited string of the IDs
     */
    protected function processIds($ids = '',$type = 'parents',$depth = 10) {
        if (!strlen($ids)) return '';
        $ids = $this->cleanIds($ids);
    	switch ($type) {
            case 'parents':
                $idArray = explode(',', $ids);
                $ids = $idArray;
                foreach ($idArray as $id) {
                    $ids = array_merge($ids,$this->modx->getChildIds($id,$depth));
                }
                $ids = array_unique($ids);
                sort($ids);
                break;
        }
        $this->ids = $ids;
        return $this->ids;
    }

    /**
     * Clean IDs
     *
     * @param string $ids Comma delimited string of IDs
     * @return string Cleaned comma delimited string of IDs
     */
    public function cleanIds($ids) {
        $pattern = array (
            '`(,)+`', //Multiple commas
            '`^(,)`', //Comma on first position
            '`(,)$`' //Comma on last position
        );
        $replace = array (
            ',',
            '',
            ''
        );
        return preg_replace($pattern, $replace, $ids);
    }

    /**
     * Either return a value or set to placeholder, depending on setting
     *
     * @param string $output
     * @param boolean $toPlaceholder
     * @return string
     */
    public function output($output = '',$toPlaceholder = false) {
        if (!empty($toPlaceholder)) {
            $this->modx->setPlaceholder($toPlaceholder,$output);
            return '';
        } else { return $output; }
    }


    /**
     * Loads the Hooks class.
     *
     * @access public
     * @param string $type The type of hook to load.
     * @param array $config An array of configuration parameters for the
     * hooks class
     * @return fiHooks An instance of the fiHooks class.
     */
    public function loadHooks($type = 'post',$config = array()) {
        if (!$this->modx->loadClass('simplesearch.siHooks',$this->config['modelPath'],true,true)) {
            $this->modx->log(modX::LOG_LEVEL_ERROR,'[SimpleSearch] Could not load Hooks class.');
            return false;
        }
        $type = $type.'Hooks';
        $this->$type = new siHooks($this,$config);
        return $this->$type;
    }
}
