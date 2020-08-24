<?php

defined('JPATH_BASE') or die();

jimport('joomla.form.formfield');
class JFormFieldGetMessageSuccess extends JFormField {

	var $type = 'getMessageSuccess';

	protected function getInput() {
		$value = htmlspecialchars($this->value, ENT_COMPAT, 'UTF-8');

		$value = trim($value);
		$value = ( empty($value) ) ? JText::_('VMPAYMENT_MESSAGE_SUCCESS_DEFAULT') : $value;
		
		$html = '<textarea name="params[message_success]" id="params_message_success" cols="120" rows="8">' . $value . '</textarea>';

		return $html;
	}

}