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
    private $idParentMenuType;
    private $idSeparatorMenuItem;

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
        $formData = $formModel->formData;

        $this->setComponentId();

        $process = $this->checkProcess();
        switch ($process) {
            case 'website':
                $this->processWebsite();
                break;
            
            case 'itens':
                $this->processMenuItens();
                break;
        }
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
        $formModel = $this->getModel();
        $listModel = $formModel->getListModel();
        $process = $this->checkProcess();

        foreach ($groups as $group) {
			foreach ($group as $rows) {
				foreach ($rows as $row) {
                    $row = $listModel->removeTableNameFromSaveData((array) $row);

                    switch ($process) {
                        case 'website':
                            $this->deleteWebsite($row);
                            break;

                        case 'itens':
                            $this->deleteMenuItem($row);
                            break;
                    }
				}
			}
		}
    }

    /**
     * This method process for website form
     * 
     * @return      null
     */
    private function processWebsite()
    {
        $this->saveMenuType();
        $this->updateUrlWebsite();
    }

    /**
     * This method process for menu itens form
     * 
     */
    private function processMenuItens()
    {
        $formModel = $this->getModel();
        $formData = $formModel->formData;

        if(!$this->checkSeparatorMenuExists() || $formData['menu_home_page'] == '1') {
            $this->saveSeparatorMenu();
        }

        $this->saveMenuItem();
    }

    /**
     * This method make the process to delete the menu itens
     * Note: We delete the menu type and separator menu item, but the menu menu itens me only call deleteMenuItem method to handle it
     * 
     * @param       array       $row        Row to delete
     * 
     * @return      null
     */
    private function deleteWebsite($row)
    {
        $modelItem = Factory::getApplication()->bootComponent('com_menus')->getMVCFactory()->createModel('Item', 'Administrator');
        $modelMenu = Factory::getApplication()->bootComponent('com_menus')->getMVCFactory()->createModel('Menu', 'Administrator');

        $modelItem->getState(); 	//We need do this to set __state_set before the save
        $modelMenu->getState(); 	//We need do this to set __state_set before the save

        // Delete the menu itens related
        $menuItens = $this->getMenuItensWebsite($this->getFormatData('id_raw', $row));
        foreach ($menuItens as $menuItem) {
            $this->deleteMenuItem($menuItem);
        }

        // Delete the respective separator menu item
        $idSeparatorMenuItem = $this->getFormatData('id_separator_menu_item_raw', $row);
        $modelItem->delete($idSeparatorMenuItem);

        // Delete the respective menu type
        $idParentMenuType = $this->getFormatData('id_parent_menutype_raw', $row);
        $modelMenu->delete($idParentMenuType);
    }

    /**
     * This method make the process to delete the menu itens
     * Note: We only move the menu item to hide menu as a parent and it update the path in adm_cloner_lists table
     * 
     * @param       array       $row        Row to delete
     * 
     * @return      null
     */
    private function deleteMenuItem($row)
    {
        $modelItem = Factory::getApplication()->bootComponent('com_menus')->getMVCFactory()->createModel('Item', 'Administrator');
        $app = Factory::getApplication();

        $modelItem->getState(); 	//We need do this to set __state_set before the save

        $listId = $this->getFormatData('menu_list_raw', $row);
        $menuId = $row['menu_id'];

        $data = $modelItem->getItem($menuId);
        $data->menutype = 'hide';
        $data->parent_id = 1;

        if (!$modelItem->save((array) $data)) {
            $app->enqueueMessage(Text::sprintf("PLG_FABRIK_FORM_JLOWCODE_SITES_ERROR_DELETE_MENU_ITEM", $modelItem->getError()));
        }

        if($listId) {
            $itemId = $modelItem->getState('item.id');
            $this->updateAdmClonerListas($listId, $modelItem->getItem($itemId)->path);
        }
    }

    /**
     * This method create or update the menu type to use as a father for the menu itens
     * 
     * @return      null
     */
    private function saveMenuType()
    {
        $modelMenu = Factory::getApplication()->bootComponent('com_menus')->getMVCFactory()->createModel('Menu', 'Administrator');
        $modelMenu->getState(); 	//We need do this to set __state_set before the save

        $formModel = $this->getModel();
        $formData = $formModel->formData;

        $siteName = $formData['name'];
        $alias = $formData['url'] ?? $siteName;
        $exist = $this->checkParentMenuTypeExists();

        $data = new stdClass();
        $data->id = $exist ? $formData['id_parent_menutype'] : 0;
        $data->menutype = $alias;
        $data->title = $siteName;
        $data->description = Text::sprintf('PLG_FABRIK_FORM_JLOWCODE_SITES_DESCRIPTION_MENU_TYPE', $siteName);
        $data->client_id = '0';

        if (!$modelMenu->save((array) $data)) {
			throw new Exception(Text::sprintf('PLG_FABRIK_FORM_JLOWCODE_SITES_ERROR_SAVE_MENU', $modelMenu->getError()));
        }

		$this->idParentMenuType = $modelMenu->getState('menu.id');
        $this->updateParentMenuType($formData['id'], $this->idParentMenuType);
    }

    /**
     * This method create or update the parent menu to use like a separator
     * 
     * @return      null
     */
    private function saveSeparatorMenu()
    {
        $app = Factory::getApplication();
        $modelItem = Factory::getApplication()->bootComponent('com_menus')->getMVCFactory()->createModel('Item', 'Administrator');
        $modelMenu = Factory::getApplication()->bootComponent('com_menus')->getMVCFactory()->createModel('Menu', 'Administrator');

        $modelItem->getState(); 	//We need do this to set __state_set before the save
        $modelMenu->getState(); 	//We need do this to set __state_set before the save

        $formModel = $this->getModel();
        $formData = $formModel->formData;

        $formDataWebsite = $this->getRowWebsite();
        $siteName = $formDataWebsite['name'];
        $alias = $formDataWebsite['url'] ?? $siteName;
        $idMenuType = $formDataWebsite['id_parent_menutype'];
        $menuType = $modelMenu->getItem($idMenuType)->menutype;

        $exist = $this->checkSeparatorMenuExists();
        $id = $exist ? $formDataWebsite['id_separator_menu_item'] : 0;
        $listId = $this->getFormatData('menu_list');

        if(empty($listId)) {
            $app->enqueueMessage(Text::_("PLG_FABRIK_FORM_JLOWCODE_SITES_WARNING_LINK_AS_HOME_PAGE"), 'warning');

            $websiteId = $this->getFormatData('site');
            $idHomeScreen = $this->getIdHomeScreen($websiteId);
            $this->updateHomeScreen($idHomeScreen, $websiteId);

            return;
        }

        $data = new stdClass();
        $data->id = $id;
        $data->title = $siteName;
        $data->alias = $alias;
        $data->link = "index.php?option=com_fabrik&view=list&listid=$listId";
        $data->menutype = $menuType;
        $data->type = 'component';
        $data->published = 1;
        $data->parent_id = 1;
        $data->component_id = $this->componentId;
        $data->browserNav = 0;

        if (!$modelItem->save((array) $data)) {
			throw new Exception(Text::sprintf('PLG_FABRIK_FORM_JLOWCODE_SITES_ERROR_SAVE_MENU', $modelItem->getError()));
        }

		$this->idSeparatorMenu = $modelItem->getState('item.id');
        $parentId = $this->getFormatData('site');
        $this->updateSeparatorMenu($parentId, $this->idSeparatorMenu);
        $this->updateHomeScreen($formData['id'], $parentId);
    }

    /**
     * This method create or updated the menu itens related with parent menu
     * 
     * @return      null
     */
    private function saveMenuItem()
    {
        $app = Factory::getApplication();
        $modelItem = Factory::getApplication()->bootComponent('com_menus')->getMVCFactory()->createModel('Item', 'Administrator');
        $modelMenu = Factory::getApplication()->bootComponent('com_menus')->getMVCFactory()->createModel('Menu', 'Administrator');

        $modelItem->getState(); 	//We need do this to set __state_set before the save
        $modelMenu->getState(); 	//We need do this to set __state_set before the save
        $menu = $app->getMenu();

        $formModel = $this->getModel();
        $formData = $formModel->getData();

        $formDataWebsite = $this->getRowWebsite();
        $idMenuType = $formDataWebsite['id_parent_menutype'];
        $menuType = $modelMenu->getItem($idMenuType)->menutype;

        $listId = $this->getFormatData('menu_list');
        $source = $this->getFormatData('menu_link');
        $title = $this->getFormatData('name');
        $exist = $this->checkMenuItemExists();

        $useList = !empty($listId);
        $url = "index.php?option=com_fabrik&view=list&listid=$listId";

        if($useList) {
            $menuItem = $this->searchMenuItem($listId);
            $id = $menuItem->id ?? 0;
        }

        $id = $exist ? $this->getFormatData('menu_id') : $id;
        $link = $useList ? $url : $source;
        $type = $useList ? 'component' : 'url';
        $componentId = $useList ? $this->componentId : 0;
        $browserNav = $useList ? 0 : 1;
        $parentId = $this->getParentMenu();

        if(!$parentId) {
			throw new Exception(Text::sprintf("PLG_FABRIK_FORM_JLOWCODE_SITES_ERROR_SAVE_MENU_ITEM", $title, Text::_("PLG_FABRIK_FORM_JLOWCODE_SITES_ERROR_PARENT_NOT_FOUND")));
        }

        $data = new stdClass();
        $data->id = $id;
        $data->title = $title;
        $data->alias = $title;
        $data->link = $link;
        $data->menutype = $menuType;
        $data->type = $type;
        $data->published = 1;
        $data->parent_id = $parentId;
        $data->component_id = $componentId;
        $data->browserNav = $browserNav;

        if (!$modelItem->save((array) $data)) {
			throw new Exception(Text::sprintf("PLG_FABRIK_FORM_JLOWCODE_SITES_ERROR_SAVE_MENU_ITEM", $title, $modelItem->getError()));
        }

        $rowId = $this->getFormatData('id');
        $itemId = $modelItem->getState('item.id');

        $this->updateIdMenuItens($rowId, $itemId);
        $this->updateAdmClonerListas($listId, $modelItem->getItem($itemId)->path);
    }

    /**
     * This method verify if the onAfterProcess event is running for website form or menu itens form
     * 
     * @return      string
     */
    private function checkProcess()
    {
        $formModel = $this->getModel();
        $table = $formModel->getTableName();

        switch ($table) {
            case 'sites':
                $process = 'website';
                break;

            case 'itens_do_menu':
                $process = 'itens';
                break;
        }

        return $process;
    }

    /**
     * This method verify if the parent menu was already created
     * 
     * @return      bool
     */
    private function checkParentMenuTypeExists()
    {
        $formModel = $this->getModel();
        $formData = $formModel->formData;

        return !empty($formData['id_parent_menutype']);
    }

    /**
     * This method verify if the separator parent menu was already created
     * 
     * @return      bool
     */
    private function checkSeparatorMenuExists()
    {
        $rowWebsite = $this->getRowWebsite();
        $exists = !empty($rowWebsite['id_separator_menu_item']);

        return $exists;
    }

    /**
     * This method verify if the menu item was already created
     * 
     * @return      bool
     */
    private function checkMenuItemExists()
    {
        $formModel = $this->getModel();
        $formData = $formModel->formData;

        return !empty($formData['menu_id']);
    }

    /**
     * This method update the row in database to store the id separator parent menu
     * 
     * @param       int     $rowId                      Row id to update
     * @param       int     $idSeparatorMenuItem        Parent menu type used as father of the itens
     * 
     * @return      null
     */
    private function updateSeparatorMenu($rowId, $idSeparatorMenuItem)
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $query = $db->getQuery(true);
        $query->update($db->qn('sites'))
            ->set($db->qn('id_separator_menu_item') . " = " . $db->q($idSeparatorMenuItem))
            ->where($db->qn('id') . " = " . $db->q($rowId));
        $db->setQuery($query);
        $db->execute();
    }

    /**
     * This method update the itens table to set all menu itens from the same parent id to zero in menu_home_page column
     * 
     * @param       int     $rowId          Row id to not update
     * @param       int     $websiteId      Parent id to update the homescreen
     * 
     * @return      null
     */
    private function updateHomeScreen($rowId, $websiteId)
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $formModel = $this->getModel();
        $table = $formModel->getTableName();

        $query = $db->getQuery(true);
        $query->update($db->qn($table))
            ->set($db->qn('menu_home_page') . " = " . $db->q(0))
            ->where($db->qn('id') . " <> " . $db->q($rowId))
            ->where($db->qn('site') . " = " . $db->q($websiteId));
        $db->setQuery($query);
        $db->execute();
    }

    /**
     * This method update the row in database to store the id parent menu
     * 
     * @param       int     $rowId              Row id to update
     * @param       int     $idParentMenuType     Parent menu type used as father of the itens
     * 
     * @return      null
     */
    private function updateParentMenuType($rowId, $idParentMenuType)
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $formModel = $this->getModel();
        $table = $formModel->getTableName();

        $query = $db->getQuery(true);
        $query->update($db->qn($table))
            ->set($db->qn('id_parent_menutype') . " = " . $db->q($idParentMenuType))
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

        $formModel = $this->getModel();
        $table = $formModel->getTableName();

        $query = $db->getQuery(true);
        $query->update($db->qn($table))
            ->set($db->qn('menu_id') . " = " . $db->q($idItem))
            ->where($db->qn('id') . " = " . $db->q($rowId));
        $db->setQuery($query);
        $db->execute();
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

    /**
     * This method get the website alias and update the table to redirect correctly
     * 
     * @return      null
     */
    private function updateUrlWebsite()
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $modelMenu = Factory::getApplication()->bootComponent('com_menus')->getMVCFactory()->createModel('Menu', 'Administrator');
        $modelMenu->getState(); 	//We need do this to set __state_set before the save

        $formModel = $this->getModel();
        $table = $formModel->getTableName();
        $formData = $formModel->formData;

        $url = $modelMenu->getItem($this->idParentMenuType)->menutype;
        $id = $formData['id'];

        if(empty($url) || empty($id)) {
            return;
        }

        $query = $db->getQuery(true);
        $query->update($db->qn($table))
            ->set($db->qn('url') . ' = ' . $db->q($url))
            ->where($db->qn('id') . ' = ' . $db->q($id));
        $db->setQuery($query);
        $db->execute();
    }

    /**
     * This method giving a website id returns all menu itens related
     * 
     * @param       int         $websiteId      Id of the website to search menu itens rows
     * 
     * @return      array
     */
    private function getMenuItensWebsite($websiteId)
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $listModelMenuItens = Factory::getApplication()->bootComponent('com_fabrik')->getMVCFactory()->createModel('List', 'FabrikFEModel');
        $listModelMenuItens->setId($this->getIdListMenuItens());

        $formModelMenuItens = $listModelMenuItens->getFormModel();
        $table = $formModelMenuItens->getTableName();

        $query = $db->getQuery(true);
        $query->select($db->qn('id'))
            ->from($db->qn($table))
            ->where($db->qn('site') . " = " . $db->q($websiteId));
        $db->setQuery($query);
        $itensId = $db->loadColumn();

        $menuItensData = Array();
        foreach ($itensId as $id) {
            $menuItensData[] = (array) $listModelMenuItens->getRow($id);
        }

        return $menuItensData;
    }

    /**
     * This method get from database the current home screen giving a website id
     * The home screen cant be a url
     * 
     * @param       int     @websiteId      Id of the website to search the home page
     * 
     * @return      int
     */
    private function getIdHomeScreen($websiteId)
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $formModel = $this->getModel();
        $table = $formModel->getTableName();

        $query = $db->getQuery(true);
        $query->select($db->qn('id'))
            ->from($db->qn($table))
            ->where($db->qn('site') . " = " . $db->q($websiteId))
            ->where($db->qn('menu_home_page') . ' = ' . $db->q('1'))
            ->where($db->qn('menu_link') . ' = ' . $db->q(''))
            ->where($db->qn('menu_list') . ' IS NOT NULL');
        $db->setQuery($query);
        $id = $db->loadResult();

        return $id;
    }

    /**
     * This method return the website row for current menu item
     * 
     * @return array
     */
    private function getRowWebsite()
    {
        $listModelWebsite = Factory::getApplication()->bootComponent('com_fabrik')->getMVCFactory()->createModel('List', 'FabrikFEModel');
        $listModelWebsite->setId($this->getIdListWebsite());

        $formModel = $this->getModel();
        $formData = $formModel->formData;

        $parentId = $this->getFormatData('site');
        $rowWebsite = (array) $listModelWebsite->getRow($parentId);
        $rowWebsite = $listModelWebsite->removeTableNameFromSaveData($rowWebsite);

        return $rowWebsite;
    }

    /**
     * This method get the parent menu id from the current row
     * 
     * @return      mixed
     */
    private function getParentMenu()
    {
        $listModelWebsite = Factory::getApplication()->bootComponent('com_fabrik')->getMVCFactory()->createModel('List', 'FabrikFEModel');
        $listModelWebsite->setId($this->getIdListWebsite());

        $formModel = $this->getModel();
        $formData = $formModel->formData;
        $listModel = $formModel->getListModel();

        $parentId = $this->getFormatData('parent');
        $rowParent = $listModel->getRow($parentId);
        $parentMenuId = $this->getFormatData('menu_id', $rowParent);

        $siteId = $this->getFormatData('site');
        $rowWebsite = (array) $listModelWebsite->getRow($siteId);
        $rowWebsite = $listModelWebsite->removeTableNameFromSaveData($rowWebsite);
        $idSeparatorMenuItem = $this->getFormatData('id_separator_menu_item', $rowWebsite);

        return $parentMenuId ?? $idSeparatorMenuItem;
    }

    /**
     * This method check if data from formData is an array or not and gives the correct value
     * 
     * @param       string      $param          FormData index to get the value
     * @param       array       $formData       FormData to search
     * 
     * @return      mixed
     */
    private function getFormatData($param, $formData=false)
    {
        if($formData === false) {
            $formModel = $this->getModel();
            $formData = $formModel->formData;
        }

        $data = $formData[$param];
        $data = is_array($data) ? $data[0] : $data;

        return !empty($data) ? $data : null;
    }

    /**
     * This method get the id of the website list
     * 
     * @return      int
     */
    private function getIdListWebsite()
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $query = $db->getQuery(true);
        $query->select($db->qn('id'))
            ->from($db->qn('#__fabrik_lists'))
            ->where($db->qn('db_table_name') . ' = ' . $db->q('sites'));
        $db->setQuery($query);
        $websitesListId = $db->loadResult();

        return $websitesListId;
    }

    /**
     * This method get the id of the menu itens list
     * 
     * @return      int
     */
    private function getIdListMenuItens()
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $query = $db->getQuery(true);
        $query->select($db->qn('id'))
            ->from($db->qn('#__fabrik_lists'))
            ->where($db->qn('db_table_name') . ' = ' . $db->q('itens_do_menu'));
        $db->setQuery($query);
        $menuItensListId = $db->loadResult();

        return $menuItensListId;
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
	 * This method search into application for a menu item giving the list id
	 * 
     * @param       int         $listId         List id to search the related menu item
     * 
	 * @return		MenuItem
	 */
	private function searchMenuItem($listId)
	{
        $db = Factory::getContainer()->get('DatabaseDriver');
        $app = Factory::getApplication();

	    $menu = $app->getMenu();
        $query = $db->getQuery(true);

        $url = "index.php?option=com_fabrik&view=list&listid=$listId";

        $query->select($db->qn('id'))
            ->from($db->qn('#__menu'))
            ->where($db->qn('link') . ' = ' . $db->q($url))
            ->where($db->qn('component_id') . ' = ' . $db->q($this->componentId))
            ->where($db->qn('type') . ' = ' . $db->q('component'))
            ->order($db->qn('id'));
        $db->setQuery($query);
        $menuId = $db->loadColumn()[0];     // I'm considering that the first one is the menu item that was created by adm_cloner_lists
        $menuItem = $menu->getItem($menuId);

		return $menuItem;
	}
}