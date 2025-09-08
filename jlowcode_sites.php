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
        $this->setComponentId();

        $formModel = $this->getModel();
        $process = $this->checkProcess();
        
        foreach ($groups as $group) {
			foreach ($group as $rows) {
				foreach ($rows as $row) {
                    $row = (array) $row;

                    switch ($process) {
                        case 'website':
                            $this->deleteWebsite = true;
                            $this->deleteWebsite($row);
                            break;

                        case 'itens':
                            $this->processDeleteMenuItem($row);
                            break;
                    }
				}
			}
		}
    }

    /**
	 * Function called when the plugin loads
	 *
	 * @return  	void
	 */
    public function onLoad()
    {
        $this->loadJS();
    }

    /**
     * This method process for website form
     * 
     * @return      void
     */
    private function processWebsite()
    {
        $this->saveMenuType();
        $this->updateUrlWebsite();
    }

    /**
     * This method process for menu itens form
     * 
     * @return      void
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
     * @return      void
     */
    private function deleteWebsite($row)
    {
        $modelItem = Factory::getApplication()->bootComponent('com_menus')->getMVCFactory()->createModel('Item', 'Administrator');
        $modelMenu = Factory::getApplication()->bootComponent('com_menus')->getMVCFactory()->createModel('Menu', 'Administrator');
        $listModelWebsite = Factory::getApplication()->bootComponent('com_fabrik')->getMVCFactory()->createModel('List', 'FabrikFEModel');

        $listModelWebsite->setId($this->getIdListWebsite());
        $modelItem->getState(); 	//We need do this to set __state_set before the save
        $modelMenu->getState(); 	//We need do this to set __state_set before the save

        $row = $listModelWebsite->removeTableNameFromSaveData($row);

        // Delete the menu itens related
        $menuItens = $this->getMenuItensWebsite($this->getFormatData('id_raw', $row));
        foreach ($menuItens as $menuItem) {
            $this->processDeleteMenuItem($menuItem);
        }

        // Delete the respective separator menu item
        $idSeparatorMenuItem = $this->getFormatData('id_separator_menu_item_raw', $row);
        $modelItem->delete($idSeparatorMenuItem);

        // Delete the respective menu type
        $idParentMenuType = $this->getFormatData('id_parent_menutype_raw', $row);
        $modelMenu->delete($idParentMenuType);
    }

    /**
     * This method get all menu itens related with the given website id and delete them
     * 
     * @param       array       $row        Row to delete
     * 
     * @return      void
     */
    private function processDeleteMenuItem($row)
    {
        $listModelMenuItens = Factory::getApplication()->bootComponent('com_fabrik')->getMVCFactory()->createModel('List', 'FabrikFEModel');
        $listModelMenuItens->setId($this->getIdListMenuItens());
        
        $formModelMenuItens = $listModelMenuItens->getFormModel();
        $table = $formModelMenuItens->getTableName();

        $row = $listModelMenuItens->removeTableNameFromSaveData($row);
        $rowId = $this->getFormatData('id_raw', $row);
        $menuId = $this->getFormatData('menu_id_raw', $row);

        $itemsToDelete = $this->getChildrenItens($rowId, $table);
        $itemsToDelete[] = $rowId;

        foreach ($itemsToDelete as $item) {
            $this->deleteMenuItem($item, $itemsToDelete);
        }
    }

    /**
     * This method get all children itens of a given parent id
     * 
     * @param       int         $parentId       Parent id to search
     * @param       string      $table          Table name to search
     * 
     * @return      array
     */
    private function getChildrenItens($parentId, $table) 
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        
        $ids = [];

        $query = $db->getQuery(true)
            ->select($db->qn('id'))
            ->from($db->qn($table))
            ->where($db->qn('parent') . ' = ' . (int) $parentId)
            ->order($db->qn('id') . ' DESC');
        $db->setQuery($query);
        $children = $db->loadObjectList();

        foreach ($children as $child) {
            $ids = array_merge($ids, $this->getChildrenItens($child->id, $table));
            $ids[] = $child->id;
        }

        return $ids;
    }

    /**
     * This method make the process to delete the menu itens
     * Note: We only move the menu item to hide menu as a parent and it update the path in adm_cloner_lists table
     * 
     * @param       array       $row        Row to delete
     * 
     * @return      void
     */
    private function deleteMenuItem($rowId, $allItensToDelete)
    {
        $listModelMenuItens = Factory::getApplication()->bootComponent('com_fabrik')->getMVCFactory()->createModel('List', 'FabrikFEModel');
        $listModelMenuItens->setId($this->getIdListMenuItens());

        $row = (array) $listModelMenuItens->getRow($rowId);
        $row = $listModelMenuItens->removeTableNameFromSaveData($row);

        $menuType = $this->getFormatData('menu_type_raw', $row);
        $isHomePage = $this->getFormatData('menu_home_page_raw', $row);

        if($isHomePage && !$this->deleteWebsite) {
            $websiteId = $this->getFormatData('site_raw', $row);
            $idHomeScreen = $this->getNewHomeScreen($websiteId, $allItensToDelete);
            $this->setHomeScreen($idHomeScreen, $websiteId);
        }

        match ($menuType) {
            'lista' => $this->deleteMenuItemList($row),
            'formulario_adicionar' => $this->deleteMenuItemForm($row),
            'link_externo' => $this->deleteMenuItemLink($row),
            'visualizacao_do_item' => $this->deleteMenuItemDetailView($row)
        };

        $this->deleteMenuItemRow($rowId);
    }

    /**
     * This method delete the menu item of type list
     * 
     * @param       array       $row        Row data
     * 
     * @return      void
     */
    private function deleteMenuItemList($row)
    {
        $modelItem = Factory::getApplication()->bootComponent('com_menus')->getMVCFactory()->createModel('Item', 'Administrator');

        $listId = $this->getFormatData('menu_list_raw', $row);
        $menuId = $this->getFormatData('menu_id_raw', $row);

        $data = $modelItem->getItem($menuId);
        $data->menutype = 'hide';
        $data->parent_id = 1;

        if (!$modelItem->save((array) $data)) {
            throw new Exception(Text::sprintf("PLG_FABRIK_FORM_JLOWCODE_SITES_ERROR_DELETE_MENU_ITEM", $modelItem->getError()));
        }

        $itemId = $modelItem->getState('item.id');
        $this->updateAdmClonerListas($listId, $modelItem->getItem($itemId)->path);
    }

    /**
     * This method delete the menu item of type form
     * 
     * @param       array       $row        Row data
     * 
     * @return      void
     */
    private function deleteMenuItemForm($row)
    {
        $listId = $this->getFormatData('menu_list_raw', $row);
        $menuId = $this->getFormatData('menu_id_raw', $row);

        $this->trashMenuItem($menuId);

        $link = "index.php?option=com_fabrik&view=list&listid=$listId";
        $row['menu_id_raw'] = $this->searchMenuItem($link);
        $this->deleteMenuItemList($row);
    }

    /**
     * This method delete the menu item of type external link
     * 
     * @param       array       $row        Row data
     * 
     * @return      void
     */
    private function deleteMenuItemLink($row)
    {
        $menuId = $this->getFormatData('menu_id_raw', $row);

        $this->trashMenuItem($menuId);
    }

    /**
     * This method delete the menu item of type detail view
     * 
     * @param       array       $row        Row data
     * 
     * @return      void
     */
    private function deleteMenuItemDetailView($row)
    {
        $menuId = $this->getFormatData('menu_id_raw', $row);
        $listId = $this->getFormatData('menu_list_raw', $row);
        $websiteId = $this->getFormatData('site_raw', $row);
        $id = $this->getFormatData('id_raw', $row);

        $this->trashMenuItem($menuId);

        if(!$this->checkMenuItemDetailsViewExists($websiteId, $listId, array($id))) {
            $link = "index.php?option=com_fabrik&view=list&listid=$listId";
            $row['menu_id_raw'] = $this->searchMenuItem($link);
            $this->deleteMenuItemList($row);
        }
    }

    /**
     * This method move the menu item to trash
     * 
     * @param       int     $menuId     Menu id to move to trash
     * 
     * @return      void
     * 
     * @throws      Exception
     */
    private function trashMenuItem($menuId)
    {
        $modelItem = Factory::getApplication()->bootComponent('com_menus')->getMVCFactory()->createModel('Item', 'Administrator');

        $data = $modelItem->getItem($menuId);
        $data->published = -2;

        // First we move to trash
        if (!$modelItem->save((array) $data)) {
            throw new Exception(Text::sprintf("PLG_FABRIK_FORM_JLOWCODE_SITES_ERROR_DELETE_MENU_ITEM", $modelItem->getError()));
        }
    }

    /**
     * This method delete the menu item row from database. 
     * We cant use deleteRows from list model because it call the same event and make a loop
     * 
     * @param       int     $id     Id to delete
     * 
     * @return      void
     */
    private function deleteMenuItemRow($id)
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $listModelMenuItens = Factory::getApplication()->bootComponent('com_fabrik')->getMVCFactory()->createModel('List', 'FabrikFEModel');

        $listModelMenuItens->setId($this->getIdListMenuItens());
        $formModelMenuItens = $listModelMenuItens->getFormModel();
        $table = $formModelMenuItens->getTableName();

        $query = $db->getQuery(true);
        $query->delete($db->qn($table))
            ->where($db->qn('id') . ' = ' . $db->q($id));
        $db->setQuery($query);
        $db->execute();
    }

    /**
     * This method create or update the menu type to use as a father for the menu itens
     * 
     * @return      void
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
     * @return      void
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
     * @return      void
     */
    private function saveMenuItem()
    {
        $app = Factory::getApplication();
        $modelItem = Factory::getApplication()->bootComponent('com_menus')->getMVCFactory()->createModel('Item', 'Administrator');
        $modelMenu = Factory::getApplication()->bootComponent('com_menus')->getMVCFactory()->createModel('Menu', 'Administrator');
        $listModel = Factory::getApplication()->bootComponent('com_fabrik')->getMVCFactory()->createModel('List', 'FabrikFEModel');

        $modelItem->getState(); 	//We need do this to set __state_set before the save
        $modelMenu->getState(); 	//We need do this to set __state_set before the save
        $menu = $app->getMenu();

        $formModel = $this->getModel();
        $formData = $formModel->getData();
        $table = $formModel->getTableName();

        $formDataWebsite = $this->getRowWebsite();
        $idMenuType = $formDataWebsite['id_parent_menutype'];
        $menuType = $modelMenu->getItem($idMenuType)->menutype;

        $updateClonerLists = true;
        $menuItemType = $this->getFormatData('menu_type');
        $listId = $this->getFormatData('menu_list');
        $title = $this->getFormatData('name');
        $alias = $title;

        // Default values
        list($id, $type, $componentId, $browserNav, $parentId, $params) = $this->getDefaultValuesForMenuItem();

        switch ($menuItemType) {
            case 'lista':
                $link = "index.php?option=com_fabrik&view=list&listid=$listId";
                break;

            case 'formulario_adicionar':
                $listModel->setId($listId);
                $formId = $listModel->getFormModel()->getId();
                $link = "index.php?option=com_fabrik&view=form&formid=$formId";

                $this->updateListMenuItem($listId, $menuType);
                $alias = $title . '-form';
                $updateClonerLists = false;
                break;

            case 'visualizacao_do_item':
                $listModel->setId($listId);
                $formId = $listModel->getFormModel()->getId();

                $rowItemId = $this->app->getInput()->get("{$table}___menu_item_raw");
                $params['rowid'] = $rowItemId;
                $link = "index.php?option=com_fabrik&view=details&formid=$formId&rowid=$rowItemId";

                $this->updateListMenuItem($listId, $menuType);
                $this->updateMenuItemDetailsView($this->getFormatData('id'), $rowItemId);
                $alias = $title . '-details';
                $updateClonerLists = false;
                break;

            case 'link_externo':
                $link = $this->getFormatData('menu_link');
                $type = 'url';
                $componentId = 0;
                $browserNav = 1;
                $updateClonerLists = false;
                break;
        }

        $data = new stdClass();
        $data->id = $this->checkMenuItemId($link);
        $data->title = $title;
        $data->alias = $alias;
        $data->link = $link;
        $data->menutype = $menuType;
        $data->type = $type;
        $data->published = 1;
        $data->parent_id = $parentId;
        $data->component_id = $componentId;
        $data->browserNav = $browserNav;
        $data->params = $params;

        if (!$modelItem->save((array) $data)) {
			throw new Exception(Text::sprintf("PLG_FABRIK_FORM_JLOWCODE_SITES_ERROR_SAVE_MENU_ITEM", $title, $modelItem->getError()));
        }

        $rowId = $this->getFormatData('id');
        $itemId = $modelItem->getState('item.id');

        $this->updateIdMenuItens($rowId, $itemId);

        if($updateClonerLists) {
            $this->updateAdmClonerListas($listId, $modelItem->getItem($itemId)->path);
        }
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
     * This method verify if the given website has menu itens or not
     * 
     */
    private function checkNewWebsite()
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $listModelMenuItens = Factory::getApplication()->bootComponent('com_fabrik')->getMVCFactory()->createModel('List', 'FabrikFEModel');
        $listModelMenuItens->setId($this->getIdListMenuItens());

        $formModelMenuItens = $listModelMenuItens->getFormModel();

        $table = $formModelMenuItens->getTableName();
        $data = $this->getModel()->getData();
        $data = $listModelMenuItens->removeTableNameFromSaveData($data);

        $websiteId = $this->getFormatData('site_raw', $data);

        $query = $db->getQuery(true);
        $query->select('COUNT(*)')
            ->from($db->qn($table))
            ->where($db->qn('site') . ' = ' . $db->q($websiteId));
        $db->setQuery($query);
        $count = $db->loadResult();

        return $count == 0;
    }

    /**
     * This method check if the given list was created before as a menu item
     * 
     * @param       int     $listId     List id to check
     * 
     * @return      int
     */
    private function checkMenuItemId($url)
    {
        $listId = $this->getFormatData('menu_list');
        $exist = $this->checkMenuItemExists();
        $menuItem = $this->getMenuItemByLink($url);

        $id = $menuItem->id ?? 0;
        $id = $exist ? $this->getFormatData('menu_id') : $id;

        return $id;
    }

    /**
     * This method check if exists another menu item of type detail view for the same list in the same website
     * 
     * @param       int         $websiteId      Website id to check
     * @param       int         $listId         List id to check
     * @param       array       $excludeIds     Array of ids to exclude from the search
     * 
     * @return      bool
     */
    private function checkMenuItemDetailsViewExists($websiteId, $listId, $excludeIds = array())
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $listModelMenuItens = Factory::getApplication()->bootComponent('com_fabrik')->getMVCFactory()->createModel('List', 'FabrikFEModel');
        $listModelMenuItens->setId($this->getIdListMenuItens());

        $formModelMenuItens = $listModelMenuItens->getFormModel();
        $table = $formModelMenuItens->getTableName();

        $query = $db->getQuery(true);
        $query->select('COUNT(*)')
            ->from($db->qn($table))
            ->where($db->qn('site') . ' = ' . $db->q($websiteId))
            ->where($db->qn('menu_list') . ' = ' . $db->q($listId))
            ->where($db->qn('menu_type') . ' = ' . $db->q('visualizacao_do_item'));

        if(!empty($excludeIds)) {
            $query->where($db->qn('id') . ' NOT IN (' . implode(',', $excludeIds) . ')');
        }

        $db->setQuery($query);
        $count = $db->loadResult();

        return $count > 0;
    }

    /**
     * This method update the row in database to store the id separator parent menu
     * 
     * @param       int     $rowId                      Row id to update
     * @param       int     $idSeparatorMenuItem        Parent menu type used as father of the itens
     * 
     * @return      void
     */
    private function updateSeparatorMenu($rowId, $idSeparatorMenuItem)
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $listModelWebsite = Factory::getApplication()->bootComponent('com_fabrik')->getMVCFactory()->createModel('List', 'FabrikFEModel');
        $listModelWebsite->setId($this->getIdListWebsite());

        $formModelWebsite = $listModelWebsite->getFormModel();
        $table = $formModelWebsite->getTableName();

        $query = $db->getQuery(true);
        $query->update($db->qn($table))
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
     * @return      void
     */
    private function updateHomeScreen($rowId, $websiteId)
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $listModelMenuItens = Factory::getApplication()->bootComponent('com_fabrik')->getMVCFactory()->createModel('List', 'FabrikFEModel');
        $listModelMenuItens->setId($this->getIdListMenuItens());

        $formModelMenuItens = $listModelMenuItens->getFormModel();
        $table = $formModelMenuItens->getTableName();

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
     * @return      void
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
     * @return      void
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
     * @return      void
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
     * @return      void
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
     * This method update the menu item related with a list to add '-list' in the alias to avoid conflict with form menu item.
     * Also it update the path in adm_cloner_listas table
     * 
     * @param   int     $listId        List id to update
     * @param   string  $menuType      Menu type to set
     * 
     * @return  void
     */
    private function updateListMenuItem($listId, $menuType)
    {
        $modelItem = Factory::getApplication()->bootComponent('com_menus')->getMVCFactory()->createModel('Item', 'Administrator');
        $modelItem->getState(); 	//We need do this to set __state_set before the save

        $link = "index.php?option=com_fabrik&view=list&listid=$listId";
        $data = $this->getMenuItemByLink($link);
        $data->menutype = $menuType;
        $data->published = 1;
        $data->parent_id = $this->getParentMenu();
        $data->params = array(
            'menu_show' => '0'
        );

        if (!$modelItem->save((array) $data)) {
			throw new Exception(Text::sprintf("PLG_FABRIK_FORM_JLOWCODE_SITES_ERROR_UPDATE_LINK_MENU_ITEM", $title, $modelItem->getError()));
        }

        $itemId = $modelItem->getState('item.id');
        $this->updateAdmClonerListas($listId, $modelItem->getItem($itemId)->path);
    }

    /**
     * This method update the row in database to store the menu item
     * 
     * @param       int     $rowId                      Row id to update
     * @param       int     $idSeparatorMenuItem        Parent menu type used as father of the itens
     * 
     * @return      void
     */
    private function updateMenuItemDetailsView($rowId, $idItemDetailsView)
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $listModelMenuItens = Factory::getApplication()->bootComponent('com_fabrik')->getMVCFactory()->createModel('List', 'FabrikFEModel');
        $listModelMenuItens->setId($this->getIdListMenuItens());

        $formModelMenuItens = $listModelMenuItens->getFormModel();
        $table = $formModelMenuItens->getTableName();

        $query = $db->getQuery(true);
        $query->update($db->qn($table))
            ->set($db->qn('menu_item') . " = " . $db->q($idItemDetailsView))
            ->where($db->qn('id') . " = " . $db->q($rowId));
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
     * The home screen needs to be a list
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
            ->where($db->qn('menu_type') . ' = ' . $db->q('lista'))
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
        $rowParent = (array) $listModel->getRow($parentId);
        $rowParent = $listModel->removeTableNameFromSaveData($rowParent);
        $parentMenuId = $this->getFormatData('menu_id', $rowParent);

        $siteId = $this->getFormatData('site');
        $rowWebsite = (array) $listModelWebsite->getRow($siteId);
        $rowWebsite = $listModelWebsite->removeTableNameFromSaveData($rowWebsite);
        $idSeparatorMenuItem = $this->getFormatData('id_separator_menu_item', $rowWebsite);

        $parentId = $parentMenuId ?? $idSeparatorMenuItem;

        if(!$parentId) {
			throw new Exception(Text::sprintf("PLG_FABRIK_FORM_JLOWCODE_SITES_ERROR_SAVE_MENU_ITEM", $title, Text::_("PLG_FABRIK_FORM_JLOWCODE_SITES_ERROR_PARENT_NOT_FOUND")));
        }

        return $parentId;
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
     * This method get the default values to create a menu item
     * 
     * @return      array
     */
    private function getDefaultValuesForMenuItem()
    {
        $id = 0;
        $type = 'component';
        $componentId = $this->componentId;
        $browserNav = 0;
        $parentId = $this->getParentMenu();
        $params = array();

        return array($id, $type, $componentId, $browserNav, $parentId, $params);
    }

    /**
     * This method get a menu item given the link
     * 
     * @param       string      $url        Url to search the related menu item
     * 
     * @return      mixed
     */
    private function getMenuItemByLink($url)
    {
        $modelItem = Factory::getApplication()->bootComponent('com_menus')->getMVCFactory()->createModel('Item', 'Administrator');
        $modelItem->getState(); 	//We need do this to set __state_set before the save

        $menuItemId = $this->searchMenuItem($url);
        if($menuItemId === null) {
            return;
        }

        $menuItem = $modelItem->getItem($menuItemId);

        return $menuItem;
    }

    /**
     * This method get a new home screen id for the given website
     * We are looking for a list type menu item that:
     * 1 - it is not in the given array
     * 2 - it is not a child item
     * 3 - it is the first one created
     * 
     * @param       int     $websiteId          Id of the website to search the home page
     * @param       array   $cantBeTheseIds     Array of ids that cant be
     * 
     * @return      int
     */
    private function getNewHomeScreen($websiteId, $cantBeTheseIds)
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $listModelMenuItens = Factory::getApplication()->bootComponent('com_fabrik')->getMVCFactory()->createModel('List', 'FabrikFEModel');

        $listModelMenuItens->setId($this->getIdListMenuItens());
        $formModelMenuItens = $listModelMenuItens->getFormModel();
        $table = $formModelMenuItens->getTableName();

        $query = $db->getQuery(true);
        $query->select($db->qn('id'))
            ->from($db->qn($table))
            ->where($db->qn('menu_type') . ' = ' . $db->q('lista'))
            ->where($db->qn('menu_list') . ' IS NOT NULL')
            ->where($db->qn('parent') . ' IS NULL')
            ->where($db->qn('site') . ' = ' . $db->q($websiteId))
            ->order($db->qn('id') . ' ASC');
        $db->setQuery($query);
        $id = $db->loadColumn();

        foreach ($id as $itemId) {
            if(!in_array($itemId, $cantBeTheseIds)) {
                return $itemId;
            }
        }

        throw new Exception(Text::sprintf("PLG_FABRIK_FORM_JLOWCODE_SITES_ERROR_NO_LIST_TO_SET_HOME_SCREEN"));
    }

    /**
	 * Sets up HTML to be injected into the form's bottom
	 *
	 * @return      void
	 */
    public function getBottomContent()
    {
        $doc = Factory::getDocument();
        $doc->addStyleDeclaration('.fb_el_itens_do_menu___menu_item .fabrik.input {padding: 0px}');
    }

    /**
     * This method set component id for use in menu model
     * 
     * @return      void
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
     * This method set the menu_home_page column to 1 for the given row id
     * 
     * @param       int     $rowId          Row id to not update
     * @param       int     $websiteId      Parent id to update the homescreen
     * 
     * @return      void
     */
    private function setHomeScreen($rowId, $websiteId)
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $listModelMenuItens = Factory::getApplication()->bootComponent('com_fabrik')->getMVCFactory()->createModel('List', 'FabrikFEModel');
        $listModelMenuItens->setId($this->getIdListMenuItens());

        $formModelMenuItens = $listModelMenuItens->getFormModel();
        $table = $formModelMenuItens->getTableName();

        $query = $db->getQuery(true);
        $query->update($db->qn($table))
            ->set($db->qn('menu_home_page') . " = " . $db->q('1'))
            ->where($db->qn('id') . " = " . $db->q($rowId))
            ->where($db->qn('site') . " = " . $db->q($websiteId));
        $db->setQuery($query);
        $db->execute();
    }

    /**
	 * This method search into application for a menu item giving the url
	 * 
     * @param       string      $url            Url to search the related menu item
     * 
	 * @return		int
	 */
	private function searchMenuItem($url)
	{
        $db = Factory::getContainer()->get('DatabaseDriver');
        $app = Factory::getApplication();

	    $menu = $app->getMenu();
        $query = $db->getQuery(true);

        $query->select($db->qn('id'))
            ->from($db->qn('#__menu'))
            ->where($db->qn('link') . ' = ' . $db->q($url))
            ->where($db->qn('component_id') . ' = ' . $db->q($this->componentId))
            ->where($db->qn('type') . ' = ' . $db->q('component'))
            ->order($db->qn('id'));
        $db->setQuery($query);
        $menuId = $db->loadColumn()[0];     // I'm considering that the first one is the menu item that was created by adm_cloner_lists

		return $menuId;
	}

    /**
     * Load the javascript files of the plugin.
     *
     * @return  void
     */
    protected function loadJS()
    {
        $opts = array();
        $opts['process'] = $this->checkProcess();
        $opts['newWebsite'] = $this->checkNewWebsite();
        $options = json_encode($opts);

        $jsFiles = Array();
		$jsFiles['Fabrik'] = 'media/com_fabrik/js/fabrik.js';
        $jsFiles['FabrikJlowcode_sites'] = 'plugins/fabrik_form/jlowcode_sites/jlowcode_sites.js';

        $script = "var FabrikJlowcode_sites = new FabrikJlowcode_sites($options);";

        FabrikHelperHTML::script($jsFiles, $script);
    }
}