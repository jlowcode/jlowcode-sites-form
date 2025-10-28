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
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\CMS\Uri\Uri;
use Joomla\String\StringHelper;
use Joomla\CMS\Application\ApplicationHelper;

// Requires 
// Change to namespaces on F5
require_once JPATH_ADMINISTRATOR . '/components/com_fabrik/models/visualization.php';

/**
 * 	Plugin that displays relevant information form a form when its URL is shared
 *
 * @package     	Joomla.Plugin
 * @subpackage  	Fabrik.form.jlowcode_sites
 * @since v1.0.0
 */
class PlgFabrik_FormJlowcode_sites extends PlgFabrik_Form
{
    /**
     * @var int
     * @since v1.0.0
     */
    private int $componentId;

    /**
     * @var int
     * @since v1.0.0
     */
    private int $idParentMenuType;

    /**
     * @var array
     * @since v1.0.0
     */
    private array $formDataSet;

    /**
     * @var true
     * @since v1.0.0
     */
    private bool $deleteWebsite;

    public function __construct(&$subject, $config = array())
    {
        parent::__construct($subject, $config);
    }

    /**
     * Run right at the end of the form processing
     * form needs to be set to record in database for this to hook to be called
     *
     * @return void
     * @throws Exception
     * @since v1.0.0
     */
    public function onAfterProcess(): void
    {
        $this->setComponentId();

        $process = $this->checkProcess();
        match ($process) {
            'website' => $this->processWebsite(),
            'itens' => $this->processMenuItens(),
        };
    }

    /**
     * This method remove the menu itens created
     *
     * @param array       &$groups List data for deletion
     *
     * @return void
     * @throws Exception
     * @since v1.0.0
     */
    public function onBeforeDeleteRowsForm(array &$groups): void
    {
        $this->setComponentId();

        $process = $this->checkProcess();

        foreach ($groups as $group) {
			foreach ($group as $rows) {
				foreach ($rows as $row) {
                    $row = (array) $row;

                    match ($process) {
                        'website' => $this->deleteWebsite($row),
                        'itens' => $this->processDeleteMenuItem($row),
                    };
				}
			}
		}
    }

    /**
     * Function called when the plugin loads
     *
     * @return    void
     * @throws Exception
     * @since v1.0.0
     */
    public function onLoad(): void
    {
        $this->loadJS();
    }

    /**
     * This method process for website form
     *
     * @return void
     * @throws Exception
     * @since v1.0.0
     */
    private function processWebsite(): void
    {
        $this->saveMenuType();
        $this->updateUrlWebsite();
        $this->checkOwnerWebsite();
    }

    /**
     * This method process for menu itens form
     *
     * @return void
     * @throws Exception
     * @since v1.0.0
     */
    private function processMenuItens(): void
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
     * @param array $row Row to delete
     *
     * @return void
     * @throws Exception
     * @since v1.0.0
     */
    private function deleteWebsite(array $row): void
    {
        $this->deleteWebsite = true;

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
     * @param array $row Row to delete
     *
     * @return void
     * @throws Exception
     * @since v1.0.0
     */
    private function processDeleteMenuItem(array $row): void
    {
        $listModelMenuItens = Factory::getApplication()->bootComponent('com_fabrik')->getMVCFactory()->createModel('List', 'FabrikFEModel');
        $listModelMenuItens->setId($this->getIdListMenuItens());

        $formModelMenuItens = $listModelMenuItens->getFormModel();
        $table = $formModelMenuItens->getTableName();

        $row = $listModelMenuItens->removeTableNameFromSaveData($row);
        $rowId = $this->getFormatData('id_raw', $row);

        $itemsToDelete = $this->getChildrenItens($rowId, $table);
        $itemsToDelete[] = $rowId;

        foreach ($itemsToDelete as $item) {
            $this->deleteMenuItem($item, $itemsToDelete);
        }
    }

    /**
     * This method make the process to delete the menu itens
     * Note: We only move the menu item to hide menu as a parent, and it updates the path in adm_cloner_lists table
     *
     * @param int $rowId Row to delete
     * @param array $allItensToDelete Itens to delete
     *
     * @return      void
     * @throws Exception
     * @since v1.0.0
     */
    private function deleteMenuItem(int $rowId, array $allItensToDelete): void
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
            $this->setSeparatorMenu($idHomeScreen);
        }

        match ($menuType) {
            'lista' => $this->deleteMenuItemList($row),
            'formulario_adicionar' => $this->deleteMenuItemForm($row),
            'link_externo' => $this->deleteMenuItemLink($row),
            'visualizacao_do_item' => $this->deleteMenuItemDetailView($row),
            'pagina' => $this->deleteMenuItemPage($row)
        };

        $this->deleteMenuItemRow($rowId);
    }

    /**
     * This method giving the id row set the row as separator menu item
     *
     * @param       int     $idHomeScreen       Row id to get the data
     *
     * @return      void
     */
    private function setSeparatorMenu($idHomeScreen)
    {
        $modelMenu = Factory::getApplication()->bootComponent('com_menus')->getMVCFactory()->createModel('Menu', 'Administrator');
        $modelItem = Factory::getApplication()->bootComponent('com_menus')->getMVCFactory()->createModel('Item', 'Administrator');
        $listModel = Factory::getApplication()->bootComponent('com_fabrik')->getMVCFactory()->createModel('List', 'FabrikFEModel');
        $listModelMenuItens = Factory::getApplication()->bootComponent('com_fabrik')->getMVCFactory()->createModel('List', 'FabrikFEModel');

        $listModelMenuItens->setId($this->getIdListMenuItens());
        $modelMenu->getState(); 	//We need do this to set __state_set before the save
        $modelItem->getState(); 	//We need do this to set __state_set before the save

        $formData = (array) $listModelMenuItens->getRow($idHomeScreen);
        $formData = $listModelMenuItens->removeTableNameFromSaveData($formData);

        $this->formDataSet = $formData;
        $formDataWebsite = $this->getRowWebsite();

        $idMenuType = $this->getFormatData('id_parent_menutype_raw', $formDataWebsite);
        $menuType = $modelMenu->getItem($idMenuType)->menutype;

        $menuItemType = $this->getFormatData('menu_type_raw', $formData);
        $id = $this->getFormatData('id_separator_menu_item_raw', $formDataWebsite);
        $rowId = $this->getFormatData('menu_item_raw', $formData) ?? $this->getFormatData('menu_page_raw', $formData);
        $listId = $this->getFormatData('menu_list_raw', $formData);

        $dataToSave = match ($menuItemType) {
            'lista' => $this->handleSeparatorMenuList($listId),
            'formulario_adicionar' => $this->handleSeparatorMenuForm($listId, $menuType),
            'visualizacao_do_item' => $this->handleSeparatorMenuDetail($listId, $menuType, $rowId),
            'pagina' => $this->handleSeparatorMenuPage($rowId)
        };

        $link = $dataToSave['link'] ?? '';
        $updateClonerLists = $dataToSave['updateClonerLists'] ?? true;
        $params = $dataToSave['params'] ?? [];

        $data = new stdClass();
        $data->id = $id;
        $data->menutype = $menuType;
        $data->link = $link;
        $data->params = $params;

        if (!$modelItem->save((array) $data)) {
			throw new Exception(Text::sprintf('PLG_FABRIK_FORM_JLOWCODE_SITES_ERROR_SAVE_MENU', $modelItem->getError()));
        }

        $parentId = $this->getFormatData('site_raw', $formData);
        $this->updateHomeScreen($idHomeScreen, $parentId);
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

        if(!isset($menuId)) {
            return;
        }

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
     * This method delete the menu item of type page
     *
     * @param       array       $row        Row data
     *
     * @return      void
     */
    private function deleteMenuItemPage($row)
    {
        $modelVisualization = Factory::getApplication()->bootComponent('com_fabrik')->getMVCFactory()->createModel('Visualization', 'FabrikAdminModel');

        $menuId = $this->getFormatData('menu_id_raw', $row);
        $visualizationId = $this->getFormatData('menu_page_raw', $row);

        if(!isset($visualizationId)) {
            return;
        }

        $data = $modelVisualization->getItem($visualizationId);
        $data->published = -2;

        $modelVisualization->getState(); 	//We need do this to set __state_set before the save
        if (!$modelVisualization->save((array) $data)) {
            throw new Exception(Text::sprintf("PLG_FABRIK_FORM_JLOWCODE_SITES_ERROR_DELETE_MENU_ITEM", $modelVisualization->getError()));
        }

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

        if(!isset($menuId)) {
            return;
        }

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
        $modelItem = $app->bootComponent('com_menus')->getMVCFactory()->createModel('Item', 'Administrator');
        $modelMenu = $app->bootComponent('com_menus')->getMVCFactory()->createModel('Menu', 'Administrator');

        $modelItem->getState(); 	//We need do this to set __state_set before the save
        $modelMenu->getState(); 	//We need do this to set __state_set before the save

        $formDataWebsite = $this->getRowWebsite();
        $siteName = $this->getFormatData('name_raw', $formDataWebsite);
        $idMenuType = $this->getFormatData('id_parent_menutype_raw', $formDataWebsite);
        $menuType = $modelMenu->getItem($idMenuType)->menutype;

        $menuItemType = $this->getFormatData('menu_type_raw');
        $exist = $this->checkSeparatorMenuExists();
        $id = $exist ? $this->getFormatData('id_separator_menu_item_raw', $formDataWebsite) : 0;
        $listId = $this->getFormatData('menu_list_raw');
        $websiteId = $this->getFormatData('site_raw');
        $rowId = $this->getFormatData('id_raw');

        $alias = $this->getFormatData('url_raw', $formDataWebsite) ?? $siteName;
        $alias = $this->checkAliasNameMenuItem($modelItem, $alias, !$exist);

        if($menuItemType == 'link') {
            $app->enqueueMessage(Text::_("PLG_FABRIK_FORM_JLOWCODE_SITES_WARNING_LINK_AS_HOME_PAGE"), 'warning');

            $idHomeScreen = $this->getIdHomeScreen($websiteId);
            $this->updateHomeScreen($idHomeScreen, $websiteId);

            return;
        }

        $dataToSave = match ($menuItemType) {
            'lista' => $this->handleSeparatorMenuList($listId),
            'formulario_adicionar' => $this->handleSeparatorMenuForm($listId, $menuType),
            'visualizacao_do_item' => $this->handleSeparatorMenuDetail($listId, $menuType),
            'pagina' => $this->preHandleSeparatorMenuPage($websiteId)
        };

        $link = $dataToSave['link'] ?? '';
        $params = $dataToSave['params'] ?? [];

        $data = new stdClass();
        $data->id = $id;
        $data->title = $siteName;
        $data->alias = $alias;
        $data->link = $link;
        $data->menutype = $menuType;
        $data->type = 'component';
        $data->published = 1;
        $data->parent_id = 1;
        $data->component_id = $this->componentId;
        $data->browserNav = 0;
        $data->params = $params;

        if (!$modelItem->save((array) $data)) {
			throw new Exception(Text::sprintf('PLG_FABRIK_FORM_JLOWCODE_SITES_ERROR_SAVE_MENU', $modelItem->getError()));
        }

		$this->idSeparatorMenu = $modelItem->getState('item.id');
        $this->updateSeparatorMenu($websiteId, $this->idSeparatorMenu);
        $this->updateHomeScreen($rowId, $websiteId);
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
        $table = $this->getCurrentTableName();

        $formDataWebsite = $this->getRowWebsite();
        $idMenuType = $this->getFormatData('id_parent_menutype_raw', $formDataWebsite);
        $websiteId = $this->getFormatData('id_raw', $formDataWebsite);
        $menuType = $modelMenu->getItem($idMenuType)->menutype;

        $updateClonerLists = true;
        $menuItemType = $this->getFormatData('menu_type');
        $listId = $this->getFormatData('menu_list');
        $title = $this->getFormatData('name');
        $rowId = $this->getFormatData('id');
        $exist = $this->checkMenuItemExists();
        $alias = $this->checkAliasNameMenuItem($modelItem, $title, !$exist);

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
                $this->updateMenuItemDetailsView($rowId, $rowItemId);
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

            case 'pagina':
                $idVisualization = $this->saveVisualizationPage($websiteId);
                $link = "index.php?option=com_fabrik&view=visualization&id=$idVisualization";
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

        $itemId = $modelItem->getState('item.id');

        $this->updateIdMenuItens($rowId, $itemId);
        $this->saveOrderingMenuItem($itemId);

        if($updateClonerLists) {
            $this->updateAdmClonerListas($listId, $modelItem->getItem($itemId)->path);
        }
    }

    /**
     * This method save the ordering of menu itens after the previous saving
     *
     * @param       int     $itemId         Id of menu item
     *
     * @return      void
     */
    private function saveOrderingMenuItem($itemId)
    {
        $modelItem = Factory::getApplication()->bootComponent('com_menus')->getMVCFactory()->createModel('Item', 'Administrator');

        if(!$itemId) {
            return;
        }

        $data = $modelItem->getItem($itemId);
        $data->menuordering = $this->getMenuOrdering();

        if (!$modelItem->save((array) $data)) {
			throw new Exception(Text::sprintf("PLG_FABRIK_FORM_JLOWCODE_SITES_ERROR_SAVE_MENU_ITEM", $data->title, $modelItem->getError()));
        }
    }

    /**
     * This method create a visualization page using the element menu_content
     *
     * @param       int         $websiteId      Id of the website
     *
     * @return      int
     */
    private function saveVisualizationPage($websiteId)
    {
        $modelVisualization = Factory::getApplication()->bootComponent('com_fabrik')->getMVCFactory()->createModel('Visualization', 'FabrikAdminModel');

        if(!$websiteId) {
            return 0;
        }

        $html = $this->getFormatData('menu_content');
        $idVisualization = $this->getFormatData('menu_page') ?? 0;
        $rowId = $this->getFormatData('id');
        $label = $this->getFormatData('name');

        $opts['id'] = $idVisualization;
        $opts['label'] = Text::sprintf('PLG_FABRIK_FORM_JLOWCODE_SITES_LABEL_PLUGIN_VISUALIZATION', $label, $websiteId);
        $opts['plugin'] = 'jlowcode_websites_pages';
        $opts['published'] = '1';
        $opts['params'] = [
            'jlowcode_sites_pages_html' => $html,
            'website_id' => $websiteId
        ];

        $modelVisualization->getState(); 	//We need do this to set __state_set before the save
        if (!$modelVisualization->save($opts)) {
			throw new Exception(Text::sprintf("PLG_FABRIK_FORM_JLOWCODE_SITES_ERROR_SAVE_MENU_ITEM", $opts['label'], $modelVisualization->getError()));
        }

        $idVisualization = $modelVisualization->getState('visualization.id');
        $this->updateMenuPageId($rowId, $idVisualization);

        return $idVisualization;
    }

    /**
     * This method handle the data to save a separator menu item as a list
     *
     * @param       int         $listId         Id of the related list
     *
     * @return      array
     */
    private function handleSeparatorMenuList($listId)
    {
        $link = "index.php?option=com_fabrik&view=list&listid=$listId";

        return ['link' => $link];
    }

    /**
     * This method handle the data to save a separator menu item as a form view
     *
     * @param int $listId Related list
     * @param string $menuType Menu type of the menu
     *
     * @return array
     * @throws Exception
     * @since v1.0.0
     */
    private function handleSeparatorMenuForm(int $listId, string $menuType): array
    {
        $listModel = Factory::getApplication()->bootComponent('com_fabrik')->getMVCFactory()->createModel('List', 'FabrikFEModel');
        $listModel->setId($listId);

        $formId = $listModel->getFormModel()->getId();
        $link = "index.php?option=com_fabrik&view=form&formid=$formId";

        $this->updateListMenuItemForSeparator($listId, $menuType);

        return ['link' => $link, 'updateClonerLists' => false];
    }

    /**
     * This method handle the data to save a separator menu item as a detail view
     *
     * @param int $listId Related list
     * @param string $menuType Menu type of the menu
     * @param int $newRowId Id to use as the item
     *
     * @return      array
     *
     * @throws Exception
     * @since       v1.0.0
     */
    private function handleSeparatorMenuDetail($listId, $menuType, $newRowId=0)
    {
        $listModel = Factory::getApplication()->bootComponent('com_fabrik')->getMVCFactory()->createModel('List', 'FabrikFEModel');
        $listModel->setId($listId);

        $table = $this->getCurrentTableName();
        $formId = $listModel->getFormModel()->getId();
        $rowItemId = $this->app->getInput()->getInt("{$table}___menu_item_raw") ?? $newRowId;

        $params = [];
        $params['rowid'] = $rowItemId;
        $link = "index.php?option=com_fabrik&view=details&formid=$formId&rowid=$rowItemId";

        $this->updateListMenuItemForSeparator($listId, $menuType);
        $this->updateMenuItemDetailsView($this->getFormatData('id'), $rowItemId);
        $updateClonerLists = false;

        return ['link' => $link, 'updateClonerLists' => $updateClonerLists, 'params' => $params];
    }

    /**
     * This method save a visualization and execute the handle function
     *
     * @param       int         $websiteId      Website id to check
     *
     * @return      null
     */
    private function preHandleSeparatorMenuPage($websiteId)
    {
        $idVisualization = $this->saveVisualizationPage($websiteId);

        return $this->handleSeparatorMenuPage($idVisualization);
    }

    /**
     * This method handle the data to save a separator menu item as a page
     *
     * @param       int         $newIdVisualization       Id to use as the item
     *
     * @return      array
     */
    private function handleSeparatorMenuPage($newIdVisualization = 0)
    {
        $idVisualization = $this->getFormatData('menu_page') ?? $newIdVisualization;

        $link = "index.php?option=com_fabrik&view=visualization&id=$idVisualization&isHome=true";
        $updateClonerLists = false;
        $params = [];

        $this->updateMenuPageId($this->getFormatData('id'), $idVisualization);

        return ['link' => $link, 'updateClonerLists' => $updateClonerLists, 'params' => $params];
    }

    /**
     * This method verify if the onAfterProcess event is running for website form or menu itens form
     *
     * @return      string
     */
    private function checkProcess()
    {
        $table = $this->getCurrentTableName();

        $process = match ($table) {
            'sites' => 'website',
            'itens_do_menu' => 'itens',
            default => throw new Exception(Text::_("PLG_FABRIK_FORM_JLOWCODE_SITES_ERROR_UNKNOW_TABLE"))
        };

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
        $exists = !empty($this->getFormatData('id_separator_menu_item', $rowWebsite));

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
        $exist = $this->checkMenuItemExists();
        $menuItem = $this->getMenuItemByLink($url);

        $id = $menuItem->id ?? 0;

        return $exist ? $this->getFormatData('menu_id') : $id;
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
     * This method check if the owner of the website was changed. If yes, set a message in the session to show after the redirect
     *
     * @return      void
     */
    private function checkOwnerWebsite()
    {
        $app = Factory::getApplication();
        $listModelWebsite = $app->bootComponent('com_fabrik')->getMVCFactory()->createModel('List', 'FabrikFEModel');
        $listModelWebsite->setId($this->getIdListWebsite());

        $formModel = $this->getModel();
        $params = $formModel->getParams();

        $origData = (array) $formModel->getOrigData()[0];
        $origData = $listModelWebsite->removeTableNameFromSaveData($origData);

        $actualUserId = $this->getFormatData('created_by_raw', $origData);
        $newUserId = $this->getWebsiteOwnerId();

        if ($actualUserId == $newUserId || !isset($actualUserId)) {
            return;
        }

        $context = $formModel->getRedirectContext();
        $newUser = Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById($newUserId);

        // Url redirect to the list view of websites
        $indexPluginRedirect = array_search('redirect', $params->get('plugins'));
        $urlRedirect = $params->get('jump_page')->$indexPluginRedirect;
		$this->session->set($context . 'url', '/'.explode('/', $urlRedirect)[1]);

        $app->enqueueMessage(Text::sprintf("PLG_FABRIK_FORM_JLOWCODE_SITES_CHANGE_OWNER_WEBSITE", $newUser->get('name')), 'success');
    }

    /**
     * This method check if the current user can add itens to the website menu
     *
     * @return      bool
     */
    private function checkCanAddItem()
    {
        $ownerId = $this->getWebsiteOwnerId();
        $currentUser = Factory::getApplication()->getIdentity();

        return $ownerId == $currentUser->id || $currentUser->authorise('core.manage');
    }

    /**
     * This method check if the given alias name already exists in the menu itens table. If yes, add a number at the end to make it unique
     *
     * @param       object      $menuModel      Menu model to load the table
     * @param       string      $alias          Alias name to check
     * @param       bool        $isNew          Are we editing or adding?
     *
     * @return      string
     */
    private function checkAliasNameMenuItem($menuModel, $alias, $isNew)
    {
        if(!$isNew) {
            return $alias;
        }

        $alias = ApplicationHelper::stringURLSafe(trim($alias), '*');
        $table = $menuModel->getTable();

        while ($table->load(['alias' => $alias])) {
            $alias = StringHelper::increment($alias, 'dash');
        }

        return $alias;
    }

    /**
     * This method update the row in database to store the id of separator parent menu
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
     * This method update the itens table to set all menu itens from the same parent id to zero in menu_home_page column except the given rowId
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

        $table = $this->getCurrentTableName();

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

        $table = $this->getCurrentTableName();

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

        $table = $this->getCurrentTableName();
        $url = $modelMenu->getItem($this->idParentMenuType)->menutype;
        $id = $this->getFormatData('id');

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
     * This method update the menu item related with a list.
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
			throw new Exception(Text::sprintf("PLG_FABRIK_FORM_JLOWCODE_SITES_ERROR_UPDATE_LINK_MENU_ITEM", $modelItem->getError()));
        }

        $itemId = $modelItem->getState('item.id');
        $this->updateAdmClonerListas($listId, $modelItem->getItem($itemId)->path);
    }

    /**
     * This method update the menu item related with a list for the separador menu item.
     * Also it update the path in adm_cloner_listas table
     *
     * @param       int     $listId        List id to update
     * @param       string  $menuType      Menu type to set
     *
     * @return      void
     */
    private function updateListMenuItemForSeparator($listId, $menuType)
    {
        $modelItem = Factory::getApplication()->bootComponent('com_menus')->getMVCFactory()->createModel('Item', 'Administrator');
        $modelItem->getState(); 	//We need do this to set __state_set before the save

        $link = "index.php?option=com_fabrik&view=list&listid=$listId";
        $data = $this->getMenuItemByLink($link);
        $data->alias .= (!str_ends_with($data->alias, '-list') ? '-list' : '');
        $data->menutype = $menuType;
        $data->published = 1;
        $data->parent_id = 1;
        $data->params = array(
            'menu_show' => '0'
        );

        if (!$modelItem->save((array) $data)) {
			throw new Exception(Text::sprintf("PLG_FABRIK_FORM_JLOWCODE_SITES_ERROR_UPDATE_LINK_MENU_ITEM", $modelItem->getError()));
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
     * This method update the row in database to store the menu page id
     *
     * @param       int     $rowId         Row id to update
     * @param       int     $pageId        Id of the visualization plugin
     *
     * @return      void
     */
    private function updateMenuPageId($rowId, $pageId)
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $listModelMenuItens = Factory::getApplication()->bootComponent('com_fabrik')->getMVCFactory()->createModel('List', 'FabrikFEModel');
        $listModelMenuItens->setId($this->getIdListMenuItens());

        $formModelMenuItens = $listModelMenuItens->getFormModel();
        $table = $formModelMenuItens->getTableName();

        $query = $db->getQuery(true);
        $query->update($db->qn($table))
            ->set($db->qn('menu_page') . " = " . $db->q($pageId))
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
     *
     * @param       int     @websiteId      Id of the website to search the home page
     *
     * @return      int
     */
    private function getIdHomeScreen($websiteId)
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $table = $this->getCurrentTableName();

        $query = $db->getQuery(true);
        $query->select($db->qn('id'))
            ->from($db->qn($table))
            ->where($db->qn('site') . " = " . $db->q($websiteId))
            ->where($db->qn('menu_home_page') . ' = ' . $db->q('1'))
            ->where($db->qn('menu_type') . ' IN (' . implode(",", $db->q(['lista', 'visualizacao_do_item', 'formulario_adicionar', 'pagina'])) . ')');
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

        $parentId = $this->getFormatData('site_raw');
        $rowWebsite = (array) $listModelWebsite->getRow($parentId);
        $rowWebsite = $listModelWebsite->removeTableNameFromSaveData($rowWebsite);

        return $rowWebsite;
    }

    /**
     * This method get the parent menu id from the current row
     *
     * @return mixed
     * @throws Exception
     * @since v1.0.0
     */
    private function getParentMenu(): mixed
    {
        $listModelWebsite = Factory::getApplication()->bootComponent('com_fabrik')->getMVCFactory()->createModel('List', 'FabrikFEModel');
        $listModelWebsite->setId($this->getIdListWebsite());

        $formModel = $this->getModel();
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
			throw new Exception(Text::sprintf("PLG_FABRIK_FORM_JLOWCODE_SITES_ERROR_SAVE_MENU_ITEM", Text::_("PLG_FABRIK_FORM_JLOWCODE_SITES_ERROR_PARENT_NOT_FOUND")));
        }

        return $parentId;
    }

    /**
     * This method check if data from formData is an array or not and gives the correct value
     * Important: when the user is deleting something we need set the formData before.
     *
     * @param string $param FormData index to get the value
     * @param bool|array $formData FormData to search
     *
     * @return mixed
     * @since v1.0.0
     */
    private function getFormatData(string $param, bool|array $formData=false): mixed
    {
        if($formData === false) {
            $formModel = $this->getModel();
            $formData = $formModel->formData;
        }

        $formData = !empty($this->formDataSet) ? $this->formDataSet : $formData;
        $this->formDataSet = array();

        $data = $formData[$param];
        $data = is_array($data) ? $data[0] : $data;

        return !empty($data) ? $data : null;
    }

    /**
     * This method get the id of the website list
     *
     * @return  int
     * @since v1.0.0
     */
    private function getIdListWebsite(): int
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $query = $db->getQuery(true);
        $query->select($db->qn('id'))
            ->from($db->qn('#__fabrik_lists'))
            ->where($db->qn('db_table_name') . ' = ' . $db->q('sites'));
        $db->setQuery($query);

        return $db->loadResult();
    }

    /**
     * This method get the id of the menu itens list
     *
     * @return int
     * @since v1.0.0
     */
    private function getIdListMenuItens(): int
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $query = $db->getQuery(true);
        $query->select($db->qn('id'))
            ->from($db->qn('#__fabrik_lists'))
            ->where($db->qn('db_table_name') . ' = ' . $db->q('itens_do_menu'));
        $db->setQuery($query);

        return $db->loadResult();
    }

    /**
     * This method get the default values to create a menu item
     *
     * @return array
     * @throws Exception
     * @since v1.0.0
     */
    private function getDefaultValuesForMenuItem(): array
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
     * @param string $url Url to search the related menu item
     *
     * @return mixed
     * @throws Exception
     * @since v1.0.0
     */
    private function getMenuItemByLink(string $url): mixed
    {
        $modelItem = Factory::getApplication()->bootComponent('com_menus')->getMVCFactory()->createModel('Item', 'Administrator');
        $modelItem->getState(); 	//We need do this to set __state_set before the save

        $menuItemId = $this->searchMenuItem($url);
        if($menuItemId === null) {
            return null;
        }

        return $modelItem->getItem($menuItemId);
    }

    /**
     * This method get a new home screen id for the given website
     * We are looking for a list type menu item that:
     * 1 - it is not in the given array
     * 2 - it is not a child item
     * 3 - it is the first one created
     *
     * @param int $websiteId Id of the website to search the home page
     * @param array $cantBeTheseIds Array of ids that cant be
     *
     * @return int
     * @throws Exception
     * @since v1.0.0
     */
    private function getNewHomeScreen(int $websiteId, array $cantBeTheseIds): int
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $listModelMenuItens = Factory::getApplication()->bootComponent('com_fabrik')->getMVCFactory()->createModel('List', 'FabrikFEModel');

        $listModelMenuItens->setId($this->getIdListMenuItens());
        $formModelMenuItens = $listModelMenuItens->getFormModel();
        $table = $formModelMenuItens->getTableName();

        $query = $db->getQuery(true);
        $query->select($db->qn('id'))
            ->from($db->qn($table))
            ->where($db->qn('menu_type') . ' IN (' . implode(",", $db->q(['lista', 'visualizacao_do_item', 'formulario_adicionar', 'pagina'])) . ')')
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
	 * @return void
     * @since v1.0.0
	 */
    public function getBottomContent(): void
    {
        $doc = Factory::getDocument();
        $doc->addStyleDeclaration('.fb_el_itens_do_menu___menu_item .fabrik.input {padding: 0px}');
    }

    /**
     * This method get the current table name
     *
     * @return string
     * @since v1.0.0
     */
    private function getCurrentTableName(): string
    {
        $formModel = $this->getModel();

        return $formModel->getTableName();
    }

    /**
     * This method get all children itens of a given parent id
     *
     * @param int $parentId       Parent id to search
     * @param string $table          Table name to search
     *
     * @return array
     * @since v1.0.0
     */
    private function getChildrenItens(int $parentId, string $table): array
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
     * This method return the menu ordering
     * To work correctly this feature needs of ordering plugin element
     *
     * @return int
     * @throws Exception
     * @since v1.0.0
     */
    private function getMenuOrdering(): int
    {
        $listModelMenuItens = Factory::getApplication()->bootComponent('com_fabrik')->getMVCFactory()->createModel('List', 'FabrikFEModel');
        $listModelMenuItens->setId($this->getIdListMenuItens());

        $rowId = $this->getFormatData('menu_ordering_orig');

        // If first option or nothing was selected then the new menu item will be added as the first one
        if($rowId == '-1' || !isset($rowId)) {
            return -1;
        }

        $row = (array) $listModelMenuItens->getRow($rowId);
        $row = $listModelMenuItens->removeTableNameFromSaveData($row);

        return $this->getFormatData('menu_id', $row);
    }

    /**
     * This method get the user id that is the owner of the website
     *
     * @return null|int
     * @throws Exception
     * @since v1.0.0
     */
    private function getWebsiteOwnerId(): null|int
    {
        $listModelWebsite = Factory::getApplication()->bootComponent('com_fabrik')->getMVCFactory()->createModel('List', 'FabrikFEModel');
        $listModelWebsite->setId($this->getIdListWebsite());

        $formModel = $this->getModel();
        $formData = $formModel->getData();
        $formData = $listModelWebsite->removeTableNameFromSaveData($formData);

        return $this->getFormatData('created_by_raw', $formData);
    }

    /**
     * This method set component id for use in menu model
     *
     * @return void
     * @since v1.0.0
     */
    private function setComponentId(): void
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
     * @param int $rowId Row id to not update
     * @param int $websiteId Parent id to update the homescreen
     *
     * @return void
     * @throws Exception
     * @since v1.0.0
     */
    private function setHomeScreen(int $rowId, int $websiteId): void
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
     * Remove the menu item id of separator menu
     *
     * @param string $url Url to search the related menu item
     *
     * @return null|int
     * @throws Exception
     * @since v1.0.0
     */
	private function searchMenuItem(string $url): null|int
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $app = Factory::getApplication();

        $formDataWebsite = $this->getRowWebsite();
        $idSeparatorMenuItem = $formDataWebsite['id_separator_menu_item'];

	    $menu = $app->getMenu();
        $query = $db->getQuery(true);

        $query->select($db->qn('id'))
            ->from($db->qn('#__menu'))
            ->where($db->qn('link') . ' = ' . $db->q($url))
            ->where($db->qn('component_id') . ' = ' . $db->q($this->componentId))
            ->where($db->qn('type') . ' = ' . $db->q('component'))
            ->where($db->qn('id') . ' <> ' . $db->q($idSeparatorMenuItem))
            ->order($db->qn('id'));
        $db->setQuery($query);

        // I'm considering that the first one is the menu item that was created by adm_cloner_lists
		return $db->loadColumn()[0];
	}

    /**
     * Load the javascript files of the plugin.
     *
     * @return void
     * @throws Exception
     * @since v1.0.0
     */
    protected function loadJS(): void
    {
        $opts = array();
        $opts['process'] = $this->checkProcess();
        $opts['newWebsite'] = $this->checkNewWebsite();
        $opts['emptyUrl'] = $this->isUrlWebsiteEmpty();
        $opts['canAddItem'] = $this->checkCanAddItem();
        $options = json_encode($opts);

        $jsFiles = Array();
		$jsFiles['Fabrik'] = 'media/com_fabrik/js/fabrik.js';
        $jsFiles['FabrikJlowcode_sites'] = 'plugins/fabrik_form/jlowcode_sites/jlowcode_sites.js';

        $script = "var FabrikJlowcode_sites = new FabrikJlowcode_sites($options);";

        FabrikHelperHTML::script($jsFiles, $script);
    }

    /**
     * This method return if the website url is empty or not to use in the website form
     *
     * @return bool
     * @since v1.0.0
     */
    private function isUrlWebsiteEmpty(): bool
    {
        $formModel = $this->getModel();

        return $formModel->isNewRecord();
    }
}