<?php
/**
 * @package ABTag
 * @copyright Copyright (c) 2017 - 2017 Lucas Sanner
 * @license GNU General Public License version 3, or later
 */

defined('_JEXEC') or die;
//Required content helper files.
require_once JPATH_SITE.'/components/com_content/helpers/association.php';
require_once JPATH_SITE.'/components/com_content/helpers/icon.php';

use Joomla\Registry\Registry;

/**
 * HTML View class for the ABTag component.
 */
class AbtagViewTag extends JViewLegacy
{
  /**
   * Execute and display a template script.
   *
   * @param   string  $tpl  The name of the template file to parse; automatically searches through the template paths.
   *
   * @return  mixed  A string if successful, otherwise a Error object.
   */

  /**
   * State data
   *
   * @var    \Joomla\Registry\Registry
   * @since  3.2
   */
  protected $state;

  /**
   * Tag items data
   *
   * @var    array
   * @since  3.2
   */
  protected $items;

  /**
   * Pagination object
   *
   * @var    JPagination
   * @since  3.2
   */
  protected $pagination;

  /**
   * @var    array  Array of leading items for blog display
   * @since  3.2
   */
  protected $lead_items = array();

  /**
   * @var    array  Array of intro (multicolumn display) items for blog display
   * @since  3.2
   */
  protected $intro_items = array();

  /**
   * @var    array  Array of links in blog display
   * @since  3.2
   */
  protected $link_items = array();

  /**
   * @var    integer  Number of columns in a multi column display
   * @since  3.2
   */
  protected $columns = 1;

  /**
   * @var    string  The name of the extension for the category
   * @since  3.2
   */
  protected $extension = 'com_abtag';

  /**
   * @var    string  Default title to use for page title
   * @since  3.2
   */
  protected $defaultPageTitle = 'JGLOBAL_ARTICLES';

  /**
   * @var    string  The name of the view to link individual items to
   * @since  3.2
   */
  protected $viewName = 'article';

  protected $tag;
  protected $children;
  protected $tagMaxLevel;
  protected $nowDate;
  protected $user;
  protected $uri;
  public $params;

  public function display($tpl = null)
  {
    $app = JFactory::getApplication();
    $this->params = $app->getParams();

    // Get some data from the models
    $this->state = $this->get('State');
    $this->items = $this->get('Items');
    $this->tag = $this->get('Tag');
    $this->children = $this->get('Children');
    $this->pagination = $this->get('Pagination');
    $this->tagMaxLevel = $this->params->get('tag_max_level');

    // Check for errors.
    if(count($errors = $this->get('Errors'))) {
      $app->enqueueMessage($errors, 'error');
      return false;
    }

    if($this->tag == false) {
      $app->enqueueMessage(JText::_('COM_ABTAG_TAG_NOT_FOUND'), 'error');
      return false;
    }

    //Check whether tag access level allows access.
    $this->user = JFactory::getUser();
    $groups = $this->user->getAuthorisedViewLevels();
    if(!in_array($this->tag->access, $groups)) {
      $app->enqueueMessage(JText::_('JERROR_ALERTNOAUTHOR'), 'error');
      return false;
    }

    // Prepare the data
    // Get the metrics for the structural page layout.
    $numLeading = $this->params->def('num_leading_entries', 1);
    $numIntro   = $this->params->def('num_intro_entries', 4);
    $numLinks   = $this->params->def('num_links', 4);
    $this->vote = JPluginHelper::isEnabled('content', 'vote');

    JPluginHelper::importPlugin('content');

    //Get the current url, (needed in the entry edit layout).
    $this->uri = JUri::getInstance();
    $this->nowDate = JFactory::getDate('now', JFactory::getConfig()->get('offset'))->toSql(true);

    // Compute the entry slugs and prepare introtext (runs content plugins).
    foreach($this->items as $item) {
      $item->slug = $item->alias ? ($item->entry_id.':'.$item->alias) : $item->entry_id;
      $item->parent_slug = ($item->parent_alias) ? ($item->parent_id.':'.$item->parent_alias) : $item->parent_id;
      // No link for ROOT category 
      if($item->parent_alias == 'root') {
	$item->parent_slug = null;
      }

      $item->catslug = $item->category_alias ? ($item->catid.':'.$item->category_alias) : $item->catid;
      $item->event   = new stdClass;

      $item->tag_id = $this->state->get('tag.id', 0);
      /*if($item->publish_up == 0) {
	$item->publish_up = $this->nowDate;
      }*/

      //Gets all the tag ids linked to the item.
      $tagIds = array();
      foreach($item->tags->itemTags as $itemTag) {
	//Note: Do not include the tag id matching the main tag id.
	if($itemTag->published == 1 && $itemTag->id != $item->main_tag_id) {
	  $tagIds[] = $itemTag->id;
	}
      }

      //Add the main tag id to the beginning of the id array.
      if($item->main_tag_id) {
	array_unshift($tagIds, $item->main_tag_id);
      }

      $item->tag_ids = $tagIds;

      $dispatcher = JEventDispatcher::getInstance();

      // Old plugins: Ensure that text property is available
      if(!isset($item->text)) {
	$item->text = $item->introtext;
      }

      //Note: Context argument is set to com_content.category instead of com_abtag.tag as
      //      we want the standard plugins (such as vote, pagenavigation etc..) to catch the events.

      $dispatcher->trigger('onContentPrepare', array ('com_content.category', &$item, &$item->params, 0));

      // Old plugins: Use processed text as introtext
      $item->introtext = $item->text;

      $results = $dispatcher->trigger('onContentAfterTitle', array('com_content.category', &$item, &$item->params, 0));
      $item->event->afterDisplayTitle = trim(implode("\n", $results));

      $results = $dispatcher->trigger('onContentBeforeDisplay', array('com_content.category', &$item, &$item->params, 0));
      $item->event->beforeDisplayContent = trim(implode("\n", $results));

      $results = $dispatcher->trigger('onContentAfterDisplay', array('com_content.category', &$item, &$item->params, 0));
      $item->event->afterDisplayContent = trim(implode("\n", $results));
    }

    // Check for layout override only if this is not the active menu item
    // If it is the active menu item, then the view and tag id will match
    $app = JFactory::getApplication();
    $active = $app->getMenu()->getActive();
    $menus = $app->getMenu();
    $pathway = $app->getPathway();
    $title = null;

    //The tag has no itemId and thus is not linked to any menu item. 
    if((!$active) || ((strpos($active->link, 'view=tag') === false) || (strpos($active->link, '&id='.(string)$this->tag->id) === false))) {
      // Get the layout from the merged tag params
      if($layout = $this->params->get('tag_layout')) {
	$this->setLayout($layout);
      }
    }
    // At this point, we are in a menu item, so we don't override the layout
    elseif(isset($active->query['layout'])) {
      // We need to set the layout from the query in case this is an alternative menu item (with an alternative layout)
      $this->setLayout($active->query['layout']);
    }
    //Note: In case the layout parameter is not found within the query, the default layout
    //      will be set.

    // For blog layouts, preprocess the breakdown of leading, intro and linked entries.
    // This makes it much easier for the designer to just interrogate the arrays.
    if(($this->params->get('layout_type') == 'blog') || ($this->getLayout() == 'blog')) {
      foreach($this->items as $i => $item) {
	if($i < $numLeading) {
	  $this->lead_items[] = $item;
	}
	elseif($i >= $numLeading && $i < $numLeading + $numIntro) {
	  $this->intro_items[] = $item;
	}
	elseif($i < $numLeading + $numIntro + $numLinks) {
	  $this->link_items[] = $item;
	}
	else {
	  continue;
	}
      }

      $this->columns = max(1, $this->params->def('num_columns', 1));

      $order = $this->params->def('multi_column_order', 1);

      if($order == 0 && $this->columns > 1) {
	// Call order down helper
	$this->intro_items = AbtagHelperQuery::orderDownColumns($this->intro_items, $this->columns);
      }
    }


    //Set the name of the active layout in params, (needed for the filter ordering layout).
    $this->params->set('active_layout', $this->getLayout());
    //Set the filter_ordering parameter for the layout.
    $this->filter_ordering = $this->state->get('list.filter_ordering');

    $this->prepareDocument();

    return parent::display($tpl);
  }


  /**
   * Method to prepares the document
   *
   * @return  void
   *
   * @since   3.2
   */
  protected function prepareDocument()
  {
    $app = JFactory::getApplication();
    // Because the application sets a default page title,
    // we need to get it from the menu item itself
    $menus = $app->getMenu();
    $menu = $menus->getActive();

    if($menu) {
      $this->params->def('page_heading', $this->params->get('page_title', $menu->title));
    }

    $title = $this->params->get('page_title', '');

    // Check for empty title and add site name if param is set
    if(empty($title)) {
      $title = $app->get('sitename');
    }
    elseif($app->get('sitename_pagetitles', 0) == 1) {
      $title = JText::sprintf('JPAGETITLE', $app->get('sitename'), $title);
    }
    elseif($app->get('sitename_pagetitles', 0) == 2) {
      $title = JText::sprintf('JPAGETITLE', $title, $app->get('sitename'));
    }

    if(empty($title)) {
      $title = $this->tag->title;
    }

    $this->document->setTitle($title);

    if($this->tag->metadesc) {
      $this->document->setDescription($this->tag->metadesc);
    }
    elseif($this->params->get('menu-meta_description')) {
      $this->document->setDescription($this->params->get('menu-meta_description'));
    }

    if($this->tag->metakey) {
      $this->document->setMetadata('keywords', $this->tag->metakey);
    }
    elseif($this->params->get('menu-meta_keywords')) {
      $this->document->setMetadata('keywords', $this->params->get('menu-meta_keywords'));
    }

    if($this->params->get('robots')) {
      $this->document->setMetadata('robots', $this->params->get('robots'));
    }

    if(!is_object($this->tag->metadata)) {
      $this->tag->metadata = new Registry($this->tag->metadata);
    }

    $mdata = $this->tag->metadata->toArray();

    if(($app->get('MetaAuthor') == '1') && $mdata['author']) {
      $this->document->setMetaData('author', $mdata['author']);
    }

    foreach($mdata as $k => $v) {
      if($v) {
	$this->document->setMetadata($k, $v);
      }
    }

    return;
  }
}
