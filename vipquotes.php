<?php
/**
 * @package      VipQuotes
 * @subpackage   Plugins
 * @author       Todor Iliev
 * @copyright    Copyright (C) 2014 Todor Iliev <todor@itprism.com>. All rights reserved.
 * @license      http://www.gnu.org/copyleft/gpl.html GNU/GPL
 */

// no direct access
defined('_JEXEC') or die;

jimport("vipquotes.init");

class plgSearchVipQuotes extends JPlugin {
    
    /**
     * Affects constructor behavior. If true, language files will be loaded automatically.
     *
     * @var    boolean
     * @since  3.1
     */
    protected $autoloadLanguage = true;
    
	/**
	 * @return array An array of search areas
	 */
	public function onContentSearchAreas() {
		static $areas = array(
			'quotes'      => 'PLG_SEARCH_VIPQUOTES_QUOTES',
			'authors'     => 'PLG_SEARCH_VIPQUOTES_AUTHORS',
			'categories'  => 'PLG_SEARCH_VIPQUOTES_CATEGORIES',
		);
		return $areas;
	}

	/**
	 * VipQuotes search method.
	 *
	 * The sql must return the following fields that are used in a common display
	 * routine: href, title, section, created, text, browsernav.
	 * 
	 * @param string Target search string
	 * @param string mathcing option, exact|any|all
	 * @param string ordering option, newest|oldest|popular|alpha|category
	 * @param mixed An array if the search it to be restricted to areas, null if search all
	 */
	public function onContentSearch($text, $phrase='', $ordering='', $areas = null) {
	    
		$app	= JFactory::getApplication();
		
		if (is_array($areas)) {
			if (!array_intersect($areas, array_keys($this->onContentSearchAreas()))) {
				return array();
			}
		} 

		$limit          = $this->params->def('search_limit',	20);
		$searchAuthor   = $this->params->get('search_author',	1);
		$searchCategory = $this->params->get('search_category',	1);
		
		$text = JString::trim($text);
		if ($text == '') {
			return array();
		}

		$return = array();
		$return = $this->searchQuotes($text, $phrase, $ordering, $limit);
		
		if($searchAuthor) {
		    $authors = $this->searchAuthors($text, $phrase, $ordering, $limit);
		    $return  = array_merge($return, $authors);
		}
		
		if($searchCategory) {
		    $categories = $this->searchCategories($text, $phrase, $ordering, $limit);
		    $return     = array_merge($return, $categories);
		}
		
		return $return;
	}
	
	/**
	 * 
	 * Search phrase in quotes.
	 * 
	 * @param string $text
	 * @param string $phrase
	 * @param string $ordering
	 * @param integer $limit
	 */
	private function searchQuotes($text, $phrase, $ordering, $limit) {
	    
	    $db		    = JFactory::getDbo();
	    $searchText = $text;
	    $wheres	    = array();
	    
		switch ($phrase){
		    
			case 'exact':
				$text		= $db->quote('%'.$db->escape($text, true).'%', false);
				$wheres[]	= 'a.quote LIKE '.$text;
				$where		= '(' . implode(') OR (', $wheres) . ')';
				break;

			case 'all':
			case 'any':
			default:
				$words	= explode(' ', $text);
				foreach ($words as $word) {
					$word		= $db->quote('%'.$db->escape($word, true).'%', false);
					$wheres[]	= 'a.quote LIKE '.$word;
					$wheres[]	= implode(' OR ', $wheres);
				}
				$where	= '(' . implode(($phrase == 'all' ? ') AND (' : ') OR ('), $wheres) . ')';
				break;
		}

		switch ($ordering) {
		    
			case 'oldest':
				$order = 'a.created ASC';
				break;

			case 'popular':
				$order = 'a.hits DESC';
				break;

			case 'alpha':
				$order = 'a.quote ASC';
				break;

			case 'category':
				$order = 'c.title ASC';
				break;

			case 'newest':
			default:
				$order = 'a.created DESC';
				break;
				
		}

		$return = array();
		
		$query	= $db->getQuery(true);

		$case_when1 = ' CASE WHEN ';
		$case_when1 .= $query->charLength('c.alias');
		$case_when1 .= ' THEN ';
		$c_id = $query->castAsChar('c.id');
		$case_when1 .= $query->concatenate(array($c_id, 'c.alias'), ':');
		$case_when1 .= ' ELSE ';
		$case_when1 .= $c_id.' END as catslug';
		
		// Select
		$query->select('a.id, a.quote AS text, a.created, a.catid');
		$query->select('b.name as author');
		$query->select('c.title as section, 2 AS browsernav, '.$case_when1);
		
		// FROM and JOIN
		$query->from($db->quoteName('#__vq_quotes', 'a'));
		$query->innerJoin($db->quoteName('#__vq_authors', 'b') .' ON a.author_id = b.id');
		$query->innerJoin($db->quoteName('#__categories', 'c') .' ON c.id = a.catid');
		
		// WHERE
		$query->where("( a.published = 1 )");
		$query->where($where);
		
		// ORDER
		$query->order($order);
		
	    $db->setQuery($query, 0, $limit);
		
		$rows     = $db->loadObjectList();
		
		if ($rows) {
		    
			foreach($rows as $key => $row) {
				$rows[$key]->href       = VipQuotesHelperRoute::getQuoteRoute($row->id, $row->catslug);
				$rows[$key]->title      = JText::sprintf("PLG_SEARCH_VIPQUOTES_RESULT_TITLE", $rows[$key]->section, $rows[$key]->author);
				$rows[$key]->text       = strip_tags($rows[$key]->text);
			}

			foreach($rows as $key => $quote) {
				if (searchHelper::checkNoHTML($quote, $searchText, array('url', 'text', 'title'))) {
					$return[] = $quote;
				}
			}
		}
		
		return $return;
	}
	
	private function searchAuthors($text, $phrase, $ordering, $limit) {
	    
	    $db		    = JFactory::getDbo();
	    $searchText = $text;
	    $wheres	    = array();
	    
		switch ($phrase){
		    
			case 'exact':
				$text		= $db->quote('%'.$db->escape($text, true).'%', false);
				$wheres[]	= 'a.name LIKE '.$text;
				$where		= '(' . implode(') OR (', $wheres) . ')';
				break;

			case 'all':
			case 'any':
			default:
				$words	= explode(' ', $text);
				foreach ($words as $word) {
					$word		= $db->quote('%'.$db->escape($word, true).'%', false);
					$wheres[]	= 'a.name LIKE '.$word;
					$wheres[]	= implode(' OR ', $wheres);
				}
				$where	= '(' . implode(($phrase == 'all' ? ') AND (' : ') OR ('), $wheres) . ')';
				break;
		}

		switch ($ordering) {
		    
			case 'oldest':
				$order = 'a.id ASC';
				break;

			case 'popular':
				$order = 'a.hits DESC';
				break;

			case 'alpha':
				$order = 'a.name ASC';
				break;

			case 'category':
				$order = 'a.name ASC';
				break;

			case 'newest':
			default:
				$order = 'a.id DESC';
				
		}

		$return = array();
		
		$query	= $db->getQuery(true);

		$case_when1 = ' CASE WHEN ';
		$case_when1 .= $query->charLength('a.alias');
		$case_when1 .= ' THEN ';
		$a_id = $query->castAsChar('a.id');
		$case_when1 .= $query->concatenate(array($a_id, 'a.alias'), ':');
		$case_when1 .= ' ELSE ';
		$case_when1 .= $a_id.' END as slug';
		
		// Select
		$query->select('a.id, a.name AS title, a.bio AS text');
		$query->select('2 AS browsernav, '.$case_when1);
		
		// FROM and JOIN
		$query->from($db->quoteName('#__vq_authors', 'a'));
		
		// WHERE
		$query->where("( a.published = 1 )");
		$query->where($where);
		
		// ORDER
		$query->order($order);
		
	    $db->setQuery($query, 0, $limit);
		
		$rows     = $db->loadObjectList();
		
		$section  = JText::_('PLG_SEARCH_VIPQUOTES');
		
		$return   = array();
		if ($rows) {
		    
			foreach($rows as $key => $row) {
			    
				$rows[$key]->href       = VipQuotesHelperRoute::getAuthorRoute($row->slug);
				$rows[$key]->text       = strip_tags($rows[$key]->text);
				$rows[$key]->section    = $section;
				$rows[$key]->created    = null;
			}

			foreach($rows as $key => $quote) {
				if (searchHelper::checkNoHTML($quote, $searchText, array('url', 'text', 'title'))) {
					$return[] = $quote;
				}
			}
		}
		
		return $return;
	}
	
	private function searchCategories($text, $phrase, $ordering, $limit) {
	     
	    $db		    = JFactory::getDbo();
	    $searchText = $text;
	    $wheres	    = array();
	     
	    switch ($phrase){
	
	        case 'exact':
	            $text		= $db->quote('%'.$db->escape($text, true).'%', false);
	            $wheres[]	= 'a.title LIKE '.$text;
	            $where		= '(' . implode(') OR (', $wheres) . ')';
	            break;
	
	        case 'all':
	        case 'any':
	        default:
	            $words	= explode(' ', $text);
	            foreach ($words as $word) {
	                $word		= $db->quote('%'.$db->escape($word, true).'%', false);
	                $wheres[]	= 'a.title LIKE '.$word;
	                $wheres[]	= implode(' OR ', $wheres);
	            }
	            $where	= '(' . implode(($phrase == 'all' ? ') AND (' : ') OR ('), $wheres) . ')';
	            break;
	    }
	
	    switch ($ordering) {
	
	        case 'oldest':
	            $order = 'a.id ASC';
	            break;
	
	        case 'popular':
	            $order = 'a.hits DESC';
	            break;
	
	        case 'alpha':
	            $order = 'a.title ASC';
	            break;
	
	        case 'category':
	            $order = 'a.title ASC';
	            break;
	
	        case 'newest':
	        default:
	            $order = 'a.id DESC';
	
	    }
	
	    $return = array();
	
	    $query	= $db->getQuery(true);
	
	    $case_when1 = ' CASE WHEN ';
	    $case_when1 .= $query->charLength('a.alias');
	    $case_when1 .= ' THEN ';
	    $a_id = $query->castAsChar('a.id');
	    $case_when1 .= $query->concatenate(array($a_id, 'a.alias'), ':');
	    $case_when1 .= ' ELSE ';
	    $case_when1 .= $a_id.' END as slug';
	
	    // Select
	    $query->select('a.id, a.title AS title, a.description AS text, a.created_time');
	    $query->select('2 AS browsernav, '.$case_when1);
	
	    // FROM and JOIN
	    $query->from($db->quoteName('#__categories', 'a'));
	
	    // WHERE
	    $query->where("( a.extension = ".$db->quote("com_vipquotes").")");
	    $query->where("( a.published = 1 )");
	    $query->where($where);
	
	    // ORDER
	    $query->order($order);
	
	    $db->setQuery($query, 0, $limit);
	
	    $rows     = $db->loadObjectList();
	
	    $section  = JText::_('PLG_SEARCH_VIPQUOTES');
	
	    $return   = array();
	    if ($rows) {
	
	        foreach($rows as $key => $row) {
	             
	            $rows[$key]->href       = VipQuotesHelperRoute::getCategoryRoute($row->slug);
	            $rows[$key]->text       = strip_tags($rows[$key]->text);
	            $rows[$key]->section    = $section;
	            $rows[$key]->created    = $rows[$key]->created_time;
	        }
	
	        foreach($rows as $key => $quote) {
	            if (searchHelper::checkNoHTML($quote, $searchText, array('url', 'text', 'title'))) {
	                $return[] = $quote;
	            }
	        }
	    }
	
	    return $return;
	}
}
