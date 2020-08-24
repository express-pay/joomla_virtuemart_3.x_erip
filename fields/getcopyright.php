<?php

defined('JPATH_BASE') or die();

jimport('joomla.form.formfield');
class JFormFieldGetCopyright extends JFormField {

	var $type = 'getCopyright';

	protected function getInput() {	
		$html = "<div style='text-align: center; margin-top: 20px;'>" . JText::_('VMPAYMENT_COPYRIGHT') . date("Y");
		$html .= "<br/>" . JText::_('VMPAYMENT_VERSION') . "2.4" . "</div>";

		return $html;
	}

}