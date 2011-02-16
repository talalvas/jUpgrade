<?php
/**
 * jUpgrade
 *
 * @version		$Id$
 * @package		MatWare
 * @subpackage	com_jupgrade
 * @copyright	Copyright 2006 - 2011 Matias Aguire. All rights reserved.
 * @license		GNU General Public License version 2 or later.
 * @author		Matias Aguirre <maguirre@matware.com.ar>
 * @link		http://www.matware.com.ar
 */

/**
 * Upgrade class for Menus
 *
 * This class takes the menus from the existing site and inserts them into the new site.
 *
 * @since	0.4.5
 */
class jUpgradeMenu extends jUpgrade
{
	/**
	 * @var		string	The name of the source database table.
	 * @since	0.4.5
	 */
	protected $source = '#__menu AS m';

	/**
	 * @var		string	The name of the destination database table.
	 * @since	0.4.8
	 */
	protected $destination = '#__menu';

	/**
	 * Get the raw data for this part of the upgrade.
	 *
	 * @return	array	Returns a reference to the source data array.
	 * @since	0.4.5
	 * @throws	Exception
	 */
	protected function &getSourceData()
	{
		// Getting the categories id's
		$categories = $this->getCatIDList();

		// Creating the query
		$join = array();
		$join[] = 'LEFT JOIN #__components AS c ON c.id = m.componentid';
		$join[] = 'LEFT JOIN j16_extensions AS e ON e.name = c.option';

		$where = "m.name != 'Home' AND m.alias != 'home'";

		$rows = parent::getSourceData(
			 ' m.id AS sid, m.menutype, m.name AS title, m.alias, m.link, m.type,'
			.' m.published, m.parent AS parent_id, e.extension_id AS component_id,'
			.' m.sublevel AS level, m.ordering, m.checked_out, m.checked_out_time, m.browserNav,'
			.' m.access, m.params, m.lft, m.rgt, m.home',
			$join,
			$where,
			'm.id'
		);

		// Getting number of rows
		$count = count($rows);

		// Do some custom post processing on the list.
		foreach ($rows as $key => &$row)
		{
			// Converting params to JSON
			$row['params'] = $this->convertParams($row['params']);
			// Fixing parent id
			$row['parent_id'] = $row['parent_id'] == 0 ? $row['parent_id']+1 : $row['parent_id'];
			// Fixing access
			$row['access'] = $row['access'] == 0 ? 1 : $row['access']+1;
			// Fixing level
			$row['level'] = $row['level'] == 0 ? 1 : $row['level']+1;
			// Fixing language
			$row['language'] = '*';

			// Fixing menus URL's
			if ($row['link'] == 'index.php?option=com_content&view=frontpage') {
				$row['link'] = 'index.php?option=com_content&view=featured';
			}
			else if (strlen(strstr($row['link'], 'index.php?option=com_content&view=section&layout=blog'))) {
				$ex = explode('&', $row['link']);
				$id = substr($ex[3], 3);
				$row['link'] = 'index.php?option=com_content&view=category&layout=blog&id='.$categories[$id]->new;
			}

			// Joomla 1.6 database structure not allow to have duplicated aliases
			$newrows = $rows;

			for ($i=$key;$i<$count;$i++) {
				unset($newrows[$i]);
			}

			$strip = array();
			$strip[$key] = $row;

			$newrows = array_diff_key($newrows, $strip);

			foreach ($newrows as $key => &$newrow) {
				if ($newrow['alias'] != $row['alias']) {
					$row['alias'] = JFilterOutput::stringURLSafe($row['title']);
				}
				else{
					$row['alias'] = JFilterOutput::stringURLSafe($row['title'])."-".rand();
					break;
				}
			}

			// Correct path
			//$row['path'] = JFilterOutput::stringURLSafe($parent)."/".$alias;

		}

		return $rows;
	}

	/**
	 * A hook to be able to modify params prior as they are converted to JSON.
	 *
	 * @param	object	$object	A reference to the parameters as an object.
	 *
	 * @return	void
	 * @since	0.4.
	 * @throws	Exception
	 */
	protected function convertParamsHook(&$object)
	{
		if (isset($object->menu_image)) {
			if((string)$object->menu_image == '-1'){
				$object->menu_image = '';
			}
		}
	}

	/**
	 * Sets the data in the destination database.
	 *
	 * @return	void
	 * @since	0.4.
	 * @throws	Exception
	 */
	protected function setDestinationData()
	{
		// Truncate j16_jupgrade_menus table
		$clean	= $this->cleanDestinationData('j16_jupgrade_menus');

		// Get the source data.
		$rows	= $this->getSourceData();
		$table	= empty($this->destination) ? $this->source : $this->destination;

		// 
		foreach ($rows as $row)
		{
			// Convert the array into an object.
			$row = (object) $row;

			// Get oldlist values
			$oldlist = new stdClass();
			$oldlist->old = $row->sid;
			unset($row->sid);

			// Inserting the menu
			if (!$this->db_new->insertObject($table, $row)) {
				throw new Exception($this->db_new->getErrorMsg());
			}

			// Get new id
			$oldlist->new = $this->db_new->insertid();

			// Save old and new id
			if (!$this->db_new->insertObject('#__jupgrade_menus', $oldlist)) {
				throw new Exception($this->db_new->getErrorMsg());
			}

		}

		// Updating the parent id's
		foreach ($rows as $row)
		{
			// Convert the array into an object.
			$row = (object) $row;

			// Getting the new parent id
			if ($row->parent_id != 1) {
				$query = "SELECT new"
				." FROM j16_jupgrade_menus"
				." WHERE old = {$row->parent_id}"
				." LIMIT 1";
				$this->db_new->setQuery($query);
				$row->parent_id = $this->db_new->loadResult();	
			}

			$query = "UPDATE j16_menu SET parent_id='{$row->parent_id}' WHERE menutype='{$row->menutype}'"
				." AND title = '{$row->title}' AND link = '{$row->link}'";
			$this->db_new->setQuery($query);
			$this->db_new->query();

		}

	}

	/**
	 * The public entry point for the class.
	 *
	 * @return	void
	 * @since	0.5.2
	 * @throws	Exception
	 */
	public function upgrade()
	{
		if (parent::upgrade()) {
			// Rebuild the usergroup nested set values.
			$table = JTable::getInstance('Menu', 'JTable', array('dbo' => $this->db_new));

			if (!$table->rebuild()) {
				echo JError::raiseError(500, $table->getError());
			}
		}
	}

}

/**
 * Upgrade class for MenusTypes
 *
 * This class takes the menus from the existing site and inserts them into the new site.
 *
 * @since	0.4.5
 */
class jUpgradeMenuTypes extends jUpgrade
{
	/**
	 * @var		string	The name of the source database table.
	 * @since	0.4.5
	 */
	protected $source = '#__menu_types';

	/**
	 * Get the raw data for this part of the upgrade.
	 *
	 * @return	array	Returns a reference to the source data array.
	 * @since	0.4.5
	 * @throws	Exception
	 */
	protected function &getSourceData()
	{
		$rows = parent::getSourceData(
			 '*',
			null,
			$this->db_old->nameQuote('id').' > 1',
			'id'
		);

		return $rows;
	}
}
