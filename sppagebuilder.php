<?php

defined('_JEXEC') or die;

use Joomla\Registry\Registry;

require_once JPATH_ADMINISTRATOR . '/components/com_geekelasticsearch/helpers/indexer/adapter.php';

/**
 * Geek ElasticSearch adapter for com_content.
 *
 * @since  2.5
 */
class PlgGeekElasticSearchSppagebuilder extends GeekElasticSearchIndexerAdapter
{
    /**
     * The plugin identifier.
     *
     * @var    string
     * @since  2.5
     */
    protected $context = 'PageBuilderPage';

    /**
     * The extension name.
     *
     * @var    string
     * @since  2.5
     */
    protected $extension = 'com_sppagebuilder';

    /**
     * The sublayout to use when rendering the results.
     *
     * @var    string
     * @since  2.5
     */
    protected $layout = 'page';

    /**
     * The type of content that the adapter indexes.
     *
     * @var    string
     * @since  2.5
     */
    protected $type_title = 'PageBuilderPage';

    /**
     * The table name.
     *
     * @var    string
     * @since  2.5
     */
    protected $table = '#__sppagebuilder';

    /**
     * Load the language file on instantiation.
     *
     * @var    boolean
     * @since  3.1
     */
    protected $autoloadLanguage = true;

    /**
     * Method to index an item. The item must be a GeekElasticSearchIndexerResult object.
     *
     * @param   GeekElasticSearchIndexerResult  $item    The item to index as an GeekElasticSearchIndexerResult object.
     * @param   string               $format  The item format.  Not used.
     *
     * @return  void
     *
     * @since   2.5
     * @throws  Exception on database error.
     */
    protected function index(GeekElasticSearchIndexerResult $item, $format = 'html')
    {
        $item->setLanguage();

        // Check if the extension is enabled.
        if (JComponentHelper::isEnabled($this->extension) == false)
        {
            return;
        }

        // Initialise the item parameters.
        $registry = new Registry;
        $registry->loadString($item->params);
        $item->params = JComponentHelper::getParams('com_sppagebuilder', true);
        $item->params->merge($registry);

        $registry = new Registry;
        $registry->loadString($item->metadata);
        $item->metadata = $registry;

        // convert json data to HTML
        $item->summary = AddonParser::viewAddons(json_decode($item->summary), 0, 'page-' . $item->id);

        // Trigger the onContentPrepare event.
        $item->summary = GeekElasticSearchIndexerHelper::prepareContent($item->summary, $item->params);

        // Build the necessary route and path information.
        $item->url = $this->getUrl($item->id, $this->extension, $this->layout);
        $item->route = $item->url;
        $item->path = $this->getSefPath($item->url);

        // Adjust the title if necessary.
        if ($this->params->get('use_menu_title', true))
        {
            // Get the menu title if it exists.
            $title = $this->getItemMenuTitle($item->url);

            if(!empty($title)) $item->title = $title;
        }

        // Add the meta-author.
        $item->metaauthor = $item->metadata->get('author');

        // Add the type taxonomy data.
        $item->addTaxonomy('Type', 'PageBuilderPage');

        // Add the meta-data processing instructions.
        $item->addInstruction(GeekElasticSearchIndexer::META_CONTEXT, 'author');

        // Add the author taxonomy data.
        if (!empty($item->author) || !empty($item->created_by_alias))
        {
            $item->addTaxonomy('Author', $item->author);
        }

        // todo add category taxonomy data
        // we don't use categories now 

        // Add the language taxonomy data.
        $item->addTaxonomy('Language', $item->language);

        // Get content extras.
        GeekElasticSearchIndexerHelper::getContentExtras($item);

        // Index the item.
        $this->indexer->index($this->context, $item);
    }

    /**
     * Method to setup the indexer to be run.
     *
     * @return  boolean  True on success.
     *
     * @since   2.5
     */
    protected function setup()
    {
        // Load dependent classes.
        include_once JPATH_SITE . '/components/com_content/helpers/route.php';
        require_once JPATH_SITE . '/components/com_sppagebuilder/parser/addon-parser.php';

        return true;
    }

    /**
     * Method to get the SQL query used to retrieve the list of content items.
     *
     * @param   mixed  $query  A JDatabaseQuery object or null.
     *
     * @return  JDatabaseQuery  A database object.
     *
     * @since   2.5
     */
    protected function getListQuery($query = null)
    {
        $db = JFactory::getDbo();

        $query = $query instanceof JDatabaseQuery ? $query : $db->getQuery(true);
        $query->select('a.id, a.title, a.text AS summary, a.published AS state, a.access, a.ordering')
            ->select('a.created_on AS start_date, a.created_by, a.modified, a.modified_by, a.language');

        $query->select('u.name AS author')
            ->from('#__sppagebuilder AS a')
            ->join('LEFT', '#__users AS u ON u.id = a.created_by');
    
        // todo remove this!!!
        // $query->where("a.id IN(105)");

        return $query;
    }

    /**
     * Method to get the query clause for getting items to update by time.
     *
     * @param   string  $time  The modified timestamp.
     *
     * @return  JDatabaseQuery  A database object.
     *
     * @since   3.1
     */
    protected function getUpdateQueryByTime($time)
    {
        // Build an SQL query based on the modified time.
        $query = $this->db->getQuery(true)
            ->where('( a.modified >= ' . $this->db->quote($time).' OR a.created_on >= ' . $this->db->quote($time).' )');

        return $query;
    }

    protected function getSefPath($url)
    {
        $db = JFactory::getDbo();
        $query = $db->getQuery(true);

        $query
            ->select('id')
            ->from($db->quoteName('#__menu'))
            ->where($db->quoteName('link') . ' = ' . $db->quote($url))
        ;

        $db->setQuery($query);
        $itemId = $db->loadResult();
        if($itemId)
        {
            $url .= '&Itemid=' . $itemId;   
        }

        $appInstance = JApplication::getInstance('site');
        $router = $appInstance->getRouter();
        $uri = $router->build($url);
        $parsed_url = $uri->toString();
        if (strpos($parsed_url, '/administrator/') !== false) {
            $pageRoutedUrl = substr($parsed_url, strlen(JUri::base(true)) + 1);
        } else {
            $pageRoutedUrl = substr($parsed_url, strlen(JUri::root(true)) + 1);
        }

        return $pageRoutedUrl;
    }
}
