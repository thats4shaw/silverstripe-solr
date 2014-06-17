<?php

/**
 * A page type specifically used for displaying search results.
 *
 * This is an alternative encapsulation of search logic as it comprises much more than the out of the
 * box example. To use this instead of the default implementation, your search form call in Page should first
 * retrieve the SearchPage to use as its context.
 *
 * @author Nathan Glasl <nathan@silverstripe.com.au>
 * @author Marcus Nyeholt <marcus@silverstripe.com.au>
 * @license http://silverstripe.org/bsd-license/
 */

if(class_exists('SearchPage')) {

	class SolrSearchPage extends SearchPage {

		/**
		 *
		 * The facets we're interested in for this search page. This will be made a little more
		 * flexible in later releases.
		 *
		 * for example with the alchemiser module -
		 *
		 * array (
		 * 'AlcKeywords_ms',
		 * 	'AlcPerson_ms',
		 * 	'AlcCompany_ms',
		 * 	'AlcOrganization_ms',
		 * );
		 *
		 * @var array
		 */
		public static $facets = array();

		/**
		 * A local cache of the current query the user is executing based
		 * on data in the request
		 *
		 * @var SolrResultSet
		 */
		protected $query;

		/**
		 * @var SolrSearchService
		 */
		protected $solr;

		/**
		 * Used for the url param
		 *
		 * @var String
		 */
		public static $filter_param = 'filter';

		public static $dependencies = array(
			'solrSearchService'			=> '%$SolrSearchService',
		);

		/**
		 * @var SolrSearchService
		 */
		public $solrSearchService;

		/**
		 * Return the fields that can be selected for sorting operations.
		 *
		 * @param String $listType
		 * @return array
		 */
		public function getSelectableFields($listType = null, $excludeGeo = true) {
			if (!$listType) {
				$listType = $this->searchableTypes('Page');
			}

			$availableFields = $this->solrSearchService->getAllSearchableFieldsFor($listType);
			$objFields = array_combine(array_keys($availableFields), array_keys($availableFields));
			$objFields['LastEdited'] = 'LastEdited';
			$objFields['Created'] = 'Created';
			$objFields['ID'] = 'ID';
			$objFields['score'] = 'Score';

			if ($excludeGeo) {
				// need to filter out any fields that are of geopoint type, as we can't use those for search
				if (!is_array($listType)) {
					$listType = array($listType);
				}
				foreach ($listType as $classType) {
					$db = Config::inst()->get($classType, 'db');
					foreach ($db as $name => $type) {
						$type = current(explode("(", $type));
						if (is_subclass_of($type, 'SolrGeoPoint') || $type == 'SolrGeoPoint') {
							unset($objFields[$name]);
						}
					}
				}
			}

			ksort($objFields);
			return $objFields;
		}

		/**
		 * Ensures that there is always a search page
		 * by checking if there's an instance of
		 * a base SearchPage. If there
		 * is not, one is created when the DB is built.
		 */
		function requireDefaultRecords() {
			parent::requireDefaultRecords();

			if(SiteTree::get_create_default_pages()){
				$page = DataObject::get_one('SearchPage');
				if(!($page && $page->exists())) {
					$page = SolrSearchPage::create();
					$page->Title = _t('SolrSearchPage.DEFAULT_PAGE_TITLE', 'Search');
					$page->Content = '';
					$page->ResultsPerPage = 10;
					$page->Status = 'New page';
					$page->write();

					DB::alteration_message('Search page created', 'created');
				}
			}

		}

		/**
		 * Get the solr instance.
		 *
		 * Note that we do this as a method just in case we decide in future
		 * that different pages can utilise different solr instances..
		 */
		public function getSolr() {
			if (!$this->solr) {
				$this->solr = $this->solrSearchService;
			}
			return $this->solr;
		}

		/**
		 * Figures out the list of fields to use in faceting, based on configured / defaults
		 */
		public function fieldsForFacets() {
			$fields = self::$facets;

			$facetFields = array('FacetFields', 'CustomFacetFields');
			if (!$fields) {
				$fields = array();
			}

			foreach ($facetFields as $name) {
				if ($this->$name && $ff = $this->$name->getValues()) {
					$types = $this->searchableTypes('Page');
					foreach ($ff as $f) {
						$fieldName = $this->getSolr()->getSolrFieldName($f, $types);
						if (!$fieldName) {
							$fieldName = $f;
						}
						$fields[] = $fieldName;
					}
				}
			}

			return $fields;
		}

		/**
		 * Get the currently active query for this page, if any
		 *
		 * @return SolrResultSet
		 */
		public function getQuery() {
			if ($this->query) {
				return $this->query;
			}

			if (!$this->getSolr()->isConnected()) {
				return null;
			}

			$query = null;
			$builder = $this->getSolr()->getQueryBuilder($this->QueryType);

			if (isset($_GET['Search'])) {
				$query = $_GET['Search'];

				// lets convert it to a base solr query
				$builder->baseQuery($query);
			}

			$sortBy = isset($_GET['SortBy']) ? $_GET['SortBy'] : $this->SortBy;
			$sortDir = isset($_GET['SortDir']) ? $_GET['SortDir'] : $this->SortDir;
			$types = $this->searchableTypes();
			// allow user to specify specific type
			if (isset($_GET['SearchType'])) {
				$fixedType = $_GET['SearchType'];
				if (in_array($fixedType, $types)) {
					$types = array($fixedType);
				}
			}

			// (strlen($this->SearchType) ? $this->SearchType : null);

			$fields = $this->getSelectableFields();

			// if we've explicitly set a sort by, then we want to make sure we have a type
			// so we can resolve what the field name in solr is. Otherwise we don't care about type
			// overly much
			if (!count($types) && $sortBy) {
				// default to page
				$types = array('Page');
			}

			if (!isset($fields[$sortBy])) {
				$sortBy = 'score';
			}

			$sortDir = $sortDir == 'Ascending' ? 'asc' : 'desc';

			$activeFacets = $this->getActiveFacets();
			if (count($activeFacets)) {
				foreach ($activeFacets as $facetName => $facetValues) {
					foreach ($facetValues as $value) {
						$builder->addFilter($facetName, $value);
					}
				}
			}

			$offset = isset($_GET['start']) ? $_GET['start'] : 0;
			$limit = isset($_GET['limit']) ? $_GET['limit'] : ($this->ResultsPerPage ? $this->ResultsPerPage : 10);

			if (count($types)) {
				$sortBy = $this->solrSearchService->getSortFieldName($sortBy, $types);
				$builder->addFilter('ClassNameHierarchy_ms', implode(' OR ', $types));
			}

			if ($this->SearchTrees()->count()) {
				$parents = $this->SearchTrees()->column('ID');
				$builder->addFilter('ParentsHierarchy_ms', implode(' OR ', $parents));
			}

			if (!$sortBy) {
				$sortBy = 'score';
			}

			$builder->sortBy($sortBy, $sortDir);

			$selectedFields = $this->SearchOnFields->getValues();

			// the following serves two purposes; filter out the searched on fields to only those that
			// are in the actually  searched on types, and to map them to relevant solr types
			if (count($selectedFields)) {
				$mappedFields = array();
				foreach ($selectedFields as $field) {
					$mappedField = $this->getSolr()->getSolrFieldName($field, $types);
					// some fields that we're searching on don't exist in the types that the user has selected
					// to search within
					if ($mappedField) {
						$mappedFields[] = $mappedField;
					}
				}
				$builder->queryFields($mappedFields);
			}

			if ($boost = $this->BoostFields->getValues()) {
				$boostSetting = array();
				foreach ($boost as $field => $amount) {
					if ($amount > 0) {
						$boostSetting[$this->getSolr()->getSolrFieldName($field, $types)] = $amount;
					}
				}
				$builder->boost($boostSetting);
			}

			if ($boost = $this->BoostMatchFields->getValues()) {
				if (count($boost)) {
					$builder->boostFieldValues($boost);
				}
			}

			if ($filters = $this->FilterFields->getValues()) {
				if (count($filters)) {
					foreach ($filters as $filter => $val) {
						$builder->addFilter($filter, $val);
					}
				}
			}

			$params = array(
				'facet' => 'true',
				'facet.field' => $this->fieldsForFacets(),
				'facet.limit' => 10,
				'facet.mincount' => $this->MinFacetCount ? $this->MinFacetCount : 1,
				'fl' => '*,score'
			);

			$fq = $this->queryFacets();
			if (count($fq)) {
				$params['facet.query'] = array_keys($fq);
			}

			$this->extend('updateQueryBuilder', $builder);

			$this->query = $this->getSolr()->query($builder, $offset, $limit, $params);
			return $this->query;
		}

		/**
		 * Gets a list of facet based filters
		 */
		public function getActiveFacets() {
			return isset($_GET[self::$filter_param]) ? $_GET[self::$filter_param] : array();
		}

		/**
		 * Retrieve all facets in the result set in a way that can be iterated
		 * over conveniently.
		 *
		 * @return \ArrayList
		 */
		public function AllFacets() {
			if (!$this->getQuery()) {
				return new ArrayList(array());
			}

			$facets = $this->getQuery()->getFacets();
			$result = array();
			$mapping = $this->facetFieldMapping();
			foreach ($facets as $title => $items) {
				$object = new ViewableData();
				$object->Items = $this->currentFacets($title);
				$title = isset($mapping[$title]) ? $mapping[$title] : $title;
				$object->Title = Varchar::create_field('Varchar', $title);
				$result[] = $object;
			}
			return new ArrayList($result);
		}

		/**
		 * Retrieve the mapping of facet field name (eg FieldName_mt)
		 * mapped to the user entered nice name
		 *
		 * @return type
		 */
		protected function facetFieldMapping() {
			$fields = array();
			if ($this->FacetMapping && $ff = $this->FacetMapping->getValues()) {
				$types = $this->searchableTypes('Page');
				foreach ($ff as $f => $mapped) {
					$fieldName = $this->getSolr()->getSolrFieldName($f, $types);
					if (!$fieldName) {
						$fieldName = $f;
					}
					$fields[$fieldName] = $mapped;
				}
			}
			return $fields;
		}

		/**
		 * Get the list of facet values for the given term
		 *
		 * @param String $term
		 */
		public function currentFacets($term=null) {
			if (!$this->getQuery()) {
				return new ArrayList(array());
			}

			$facets = $this->getQuery()->getFacets();
			$queryFacets = $this->queryFacets();

			$me = $this;

			$convertFacets = function ($term, $raw) use ($facets, $queryFacets, $me) {
				$result = array();
				foreach ($raw as $facetTerm) {
					// if it's a query facet, then we may have a label for it
					if (isset($queryFacets[$facetTerm->Name])) {
						$facetTerm->Name = $queryFacets[$facetTerm->Name];
					}
					$sq = $me->SearchQuery();
					$sep = strlen($sq) ? '&amp;' : '';
					$facetTerm->SearchLink = $me->Link('results') . '?' . $sq .$sep. SolrSearchPage::$filter_param . "[$term][]=$facetTerm->Query";
					$facetTerm->QuotedSearchLink = $me->Link('results') . '?' . $sq .$sep. SolrSearchPage::$filter_param . "[$term][]=&quot;$facetTerm->Query&quot;";
					$result[] = new ArrayData($facetTerm);
				}
				return $result;
			};

			if ($term) {
				// return just that term
				$ret = isset($facets[$term]) ? $facets[$term] : null;
				// lets update them all and add a link parameter
				$result = array();
				if ($ret) {
					$result = $convertFacets($term, $ret);
				}

				return new ArrayList($result);
			} else {
				$all = array();
				foreach ($facets as $term => $ret) {
					$result = $convertFacets($term, $ret);
					$all = array_merge($all, $result);
				}

				return new ArrayList($all);
			}

			return new ArrayList($facets);
		}

		/**
		 * Add in any explicitly configured types.
		 *
		 * @param array $source
		 */
		public function updateSource(&$source) {
			$objects = DataObject::get('SolrTypeConfiguration');
			if ($objects) {
				foreach ($objects as $obj) {
					$source[$obj->Title] = $obj->Title;
				}
			}
		}

		/**
		 * Gets the list of query parsers available
		 *
		 * @return array
		 */
		public function getQueryBuilders() {
			return $this->solrSearchService->getQueryBuilders();
		}

	}

	class SolrSearchPage_Controller extends SearchPage_Controller {

		protected function getSolr() {
			return $this->data()->getSolr();
		}

		public function FacetCrumbs() {
			$activeFacets = $this->data()->getActiveFacets();
			$parts = array();
			$queryString = $this->data()->SearchQuery();
			if (count($activeFacets)) {
				foreach ($activeFacets as $facetName => $facetValues) {
					foreach ($facetValues as $i => $v) {
						$item = new stdClass();
						$item->Name = $v;
						$paramName = urlencode(SolrSearchPage::$filter_param . '[' . $facetName . '][' . $i . ']') .'='. urlencode($item->Name);
						$item->RemoveLink = $this->Link('results') . '?' . str_replace($paramName, '', $queryString);
						$parts[] = new ArrayData($item);
					}
				}
			}

			return new ArrayList($parts);
		}

		/**
		 * Process and render search results
		 */
		function results($data = null, $form = null){
			$query = $this->data()->getQuery();

			$term = isset($_GET['Search']) ? Convert::raw2xml($_GET['Search']) : '';

			$results = $query ? $query->getDataObjects(true) : ArrayList::create();

			$elapsed = '< 0.001';

			if ($query) {
				$resultData = array(
					'TotalResults' => (($total = $query->getTotalResults()) ? $total : 0)
				);
				$time = $query->getTimeTaken();
				if($time) {
					$elapsed = $time / 1000;
				}
			} else {
				$resultData = array();
			}

			$data = array(
				'Results'		=> $results,
				'Query'			=> Varchar::create_field('Varchar', $term),
				'Title'			=> $this->data()->Title,
				'ResultData'	=> ArrayData::create($resultData),
				'TimeTaken'		=> $elapsed
			);

			$me = $this->class . '_results';
			return $this->customise($data)->renderWith(array($me, 'SolrSearchPage_results', 'SolrSearchPage', 'Page'));
		}

		/**
		 * Return the results with a template applied to them based on the page's listing template
		 *
		 */
		public function TemplatedResults() {
			$query = $this->data()->getQuery();
			if ($this->data()->ListingTemplateID && $query) {
				$template = DataObject::get_by_id('ListingTemplate', $this->data()->ListingTemplateID);
				if ($template && $template->exists()) {
					$items = $query ? $query->getDataObjects() : new DataObjectSet();
					$item = $this->data()->customise(array('Items' => $items));
					$view = SSViewer::fromString($template->ItemTemplate);
					return $view->process($item);
				}
			}
		}

	}

}
