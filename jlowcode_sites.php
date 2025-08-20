<?php
/**
 * jLowcode Sites
 * 
 * @package     Joomla.Plugin
 * @subpackage  Fabrik.Form.jlowcode_sites
 * @copyright   Copyright (C) 2025 Jlowcode Org - All rights reserved.
 * @license     GNU/GPL http://www.gnu.org/copyleft/gpl.html
 */

// No direct access
defined('_JEXEC') or die('Restricted access');

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;

/**
 * 	Plugin that displays relevant information form a form when its URL is shared
 * 
 * @package     	Joomla.Plugin
 * @subpackage  	Fabrik.form.jlowcode_sites
 */
class PlgFabrik_FormJlowcode_sites extends PlgFabrik_Form 
{
    private $componentId;
    private $idParentMenu;
    private $repeatTable;

    public function __construct(&$subject, $config = array()) 
    {
        parent::__construct($subject, $config);
    }

    /**
	 * Run right at the end of the form processing
	 * form needs to be set to record in database for this to hook to be called
	 * 
	 * @return	bool
	 */
    public function onAfterProcess()
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $app = Factory::getApplication();
        $user = $app->getIdentity();

        $formModel = $this->getModel();
        $groups = $formModel->getPublishedGroups();
        $formData = $formModel->formData;

        $this->setTableForQuery();
        $this->setComponentId();
        $this->createParentMenu();

        $qtnItens = $this->countQtnItens($formData['id']);
        for ($i=0; $i < $qtnItens; $i++) {
            $source = $this->getRepeatableData('menu_list', $i) ?? $this->getRepeatableData('menu_link', $i);
            $title = $this->getRepeatableData('menu_title', $i);

            if(empty($title)) {
                $app->enqueueMessage(Text::sprintf("PLG_FABRIK_FORM_JLOWCODE_SITES_ERROR_NO_TITLE"));
                continue;
            }

            if(!$source) {
                $app->enqueueMessage(Text::sprintf("PLG_FABRIK_FORM_JLOWCODE_SITES_ERROR_NO_SOURCE", $title));
                continue;
            }

            $this->createItemMenu($source, $title, $i);
        }
    }

    /**
     * This method create or updated the menu itens related with parent menu
     * 
     * @param       string      $source     List id or url
     * @param       string      $title      Name to use for menu title
     * @param       int         $index      Index of repeatable line
     * 
     * @return      null
     */
    private function createItemMenu($source, $title, $index)
    {
        $app = Factory::getApplication();
        $menuModel = Factory::getApplication()->bootComponent('com_menus')->getMVCFactory()->createModel('Item', 'Administrator');
        
        $menuModel->getState(); 	//We need do this to set __state_set before the save
        $menu = $app->getMenu();

        $formModel = $this->getModel();
        $formData = $formModel->getData();

        $listId = $this->getRepeatableData('menu_list', $index);
        $exist = $this->menuItemExists($index);

        $useList = !empty($listId);
        $url = "index.php?option=com_fabrik&view=list&listid=$listId";

        if($useList) {
            $menuItem = $menu->getItems('link', $url, true);
            $id = $menuItem->id ?? 0;
        }

        $id = $exist ? $this->getIdMenuItem($index) : $id;
        $link = $useList ? $url : $source;
        $type = $useList ? 'component' : 'url';
        $componentId = $useList ? $this->componentId : 0;
        $browserNav = $useList ? 0 : 1;

        $data = new stdClass();
        $data->id = $id;
        $data->title = $title;
        $data->alias = $title;
        $data->link = $link;
        $data->menutype = 'hide';
        $data->type = $type;
        $data->published = 1;
        $data->parent_id = $this->idParentMenu;
        $data->component_id = $componentId;
        $data->browserNav = $browserNav;

        if (!$menuModel->save((array) $data)) {
			throw new Exception(Text::sprintf("PLG_FABRIK_FORM_JLOWCODE_SITES_ERROR_SAVE_MENU_ITEM", $title, $menuModel->getError()));
        }

        $idRow = $formData[$this->repeatTable . '___id'][$index];
        $idRow = is_array($idRow) ? $idRow[0] : $idRow;
        $itemId = $menuModel->getState('item.id');

        $this->updateIdMenuItens($idRow, $itemId);
        $this->updateAdmClonerListas($listId, $menuModel->getItem($itemId)->path);
    }

    /**
     * This method remove the menu itens created
     * 
	 * @param       array       &$groups        List data for deletion
	 *
	 * @return      bool
     */
    public function onDeleteRowsForm(&$groups)
    {
        $app = Factory::getApplication();
        $menuModel = Factory::getApplication()->bootComponent('com_menus')->getMVCFactory()->createModel('Item', 'Administrator');

        $menuModel->getState(); 	//We need do this to set __state_set before the save
        $this->setTableForQuery();

        $formModel = $this->getModel();
        $table = $formModel->getTableName();

        foreach ($groups as $group) {
			foreach ($group as $rows) {
				foreach ($rows as $row) {
                    $columnMenuId = $this->repeatTable . '___menu_id';
                    $columnParentMenu = $table . '___id_parent_menu';

                    $menuItens = json_decode($row->$columnMenuId);
                    $menuItens[] = $row->$columnParentMenu;

                    foreach ($menuItens as $idMenu) {
                        $data = $menuModel->getItem($idMenu);
                        $data->published = -2;

                        if (!$menuModel->save((array) $data)) {
                            $app->enqueueMessage(Text::sprintf("PLG_FABRIK_FORM_JLOWCODE_SITES_ERROR_DELETE_MENU_ITEM", $menuModel->getError()));
                        }
                    }
				}
			}
		}
    }

    /**
     * This method create or update the parent menu to use like a separator
     * 
     * @return      null
     */
    private function createParentMenu()
    {
        $menuModel = Factory::getApplication()->bootComponent('com_menus')->getMVCFactory()->createModel('Item', 'Administrator');
        $menuModel->getState(); 	//We need do this to set __state_set before the save

        $formModel = $this->getModel();
        $formData = $formModel->formData;

        $siteName = $formData['name'];
        $alias = $formData['url'] ?? $siteName;
        $exist = $this->parentMenuExists();

        $data = new stdClass();
        $data->id = $exist ? $this->getIdParentMenu() : 0;
        $data->title = $siteName;
        $data->alias = $alias;
        $data->link = '';
        $data->menutype = 'hide';
        $data->type = 'separator';
        $data->published = 1;
        $data->parent_id = 1;
        $data->component_id = 0;

        if (!$menuModel->save((array) $data)) {
			throw new Exception(Text::sprintf('PLG_FABRIK_FORM_JLOWCODE_SITES_ERROR_SAVE_MENU', $menuModel->getError()));
        }

		$this->idParentMenu = $menuModel->getState('item.id');
        $this->updateIdParentMenu($formData['id'], $this->idParentMenu);
    }

    /**
     * This method update the row in database to store the id parent menu
     * 
     * @param       int     $rowId              Row id to update
     * @param       int     $idParentMenu       Id of parent menu to store
     * 
     * @return      null
     */
    private function updateIdParentMenu($rowId, $idParentMenu)
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $formModel = $this->getModel();
        $table = $formModel->getTableName();

        $query = $db->getQuery(true);
        $query->update($db->qn($table))
            ->set($db->qn('id_parent_menu') . " = " . $db->q($idParentMenu))
            ->where($db->qn('id') . " = " . $db->q($rowId));
        $db->setQuery($query);
        $db->execute();
    }

    /**
     * This method update the row in database to store the id parent menu
     * 
     * @param       int     $rowId        Row id to update
     * @param       int     $idItem       Id of menu item to store
     * 
     * @return      null
     */
    private function updateIdMenuItens($rowId, $idItem)
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $query = $db->getQuery(true);
        $query->update($db->qn($this->repeatTable))
            ->set($db->qn('menu_id') . " = " . $db->q($idItem))
            ->where($db->qn('id') . " = " . $db->q($rowId));
        $db->setQuery($query);
        $db->execute();
    }

    /**
     * This method get the id of parent menu
     * 
     * @return      string
     */
    private function getIdParentMenu()
    {
        $formModel = $this->getModel();
        $formData = $formModel->formData;

        return $formData['id_parent_menu'];
    }

    /**
     * This method get the id of menu itens
     * 
     * @param       int         $index      Index of repeatable line
     * 
     * @return      string
     */
    private function getIdMenuItem($index)
    {
        return $this->getRepeatableData('menu_id', $index);
    }

    /**
     * This method verify if the parent menu was already created
     * 
     * @return      bool
     */
    private function parentMenuExists()
    {
        $formModel = $this->getModel();
        $formData = $formModel->formData;

        return !empty($formData['id_parent_menu']);
    }

    /**
     * This method verify if the menu item was already created
     * 
     * @param       int         $index      Index of repeatable line
     * 
     * @return      bool
     */
    private function menuItemExists($index)
    {
        $formModel = $this->getModel();
        $formData = $formModel->formData;

        return !empty($formData['menu_id'][$index]);
    }

    /**
     * This method set component id for use in menu model
     * 
     * @return      null
     */
    private function setComponentId()
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $query = $db->getQuery(true);
        $query->select($db->qn('extension_id'))
            ->from($db->qn('#__extensions'))
            ->where($db->qn('name') . ' = ' . $db->q('com_fabrik'))
            ->where($db->qn('type') . ' = ' . $db->q('component'));
        $db->setQuery($query);
        $this->componentId = $db->loadResult();
    }

    /**
     * This method check if data from formData is an array or not and gives the correct value
     * 
     * @param       string      $param      FormData index to get the value
     * @param       int         $index      Index of repeatable line
     * 
     * @return      mixed
     */
    private function getRepeatableData($param, $index)
    {
        $formModel = $this->getModel();
        $formData = $formModel->formData;

        $data = $formData[$param][$index];
        $data = is_array($data) ? $data[0] : $data;

        return !empty($data) ? $data : null;
    }

    /**
     * This method counts the number of itens for a given parent ID.
     * 
     * @param       int         $parent_id      The parent ID to filter the query.
     * 
     * @return      int
     */
    private function countQtnItens($parent_id) 
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $query = $db->getQuery(true);
        $query->select('COUNT(id)')
            ->from($db->qn($this->repeatTable))
            ->where($db->qn('parent_id') . ' = ' . $db->q((int) $parent_id));
        $db->setQuery($query);

        return $db->loadResult();
    }

    /**
     * This method sets the table for the query based on the join model of the form.
     * 
     * @return      null
     */
    private function setTableForQuery()
    {
		$joinModel = Factory::getApplication()->bootComponent('com_fabrik')->getMVCFactory()->createModel('Join', 'FabrikFEModel');

        $formModel = $this->getModel();
        $groups = $formModel->getPublishedGroups();
        $idGroupItens = array_values($groups)[1]->id;           // Repeat group with menu itens must be the second group created
        $joinModel->setId($groups[$idGroupItens]->join_id);

        $this->repeatTable = $joinModel->getJoin()->table_join;
    }

    /**
     * This method update the path in adm_cloner_listas table using menu id
     * 
     * @param       int     $listId     List id to update in adm_cloner_listas table
     * @param       int     $path       New path to update in adm_cloner_listas table
     * 
     * @return      null
     */
    private function updateAdmClonerListas($listId, $path)
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $app = Factory::getApplication();

        if(empty($listId)) {
            return;
        }

        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }

        $query = $db->getQuery(true);
        $query->update($db->qn('adm_cloner_listas'))
            ->set($db->qn('link') . ' = ' . $db->q($path))
            ->where($db->qn('id_lista') . ' = ' . $db->q($listId));
        $db->setQuery($query);
        $db->execute();
    }
}