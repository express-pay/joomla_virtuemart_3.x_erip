<?php

defined('JPATH_BASE') or die();

jimport('joomla.form.formfield');
class JFormFieldGetHandlerUrl extends JFormField {

	var $type = 'getHandlerUrl';

	protected function getInput() {
		$html = '<input type="text" name="handler_url" id="handler_url" value="' . JROUTE::_(JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginnotification&tmpl=component') . '" size="90" readonly="">';

		return $html;
	}

}