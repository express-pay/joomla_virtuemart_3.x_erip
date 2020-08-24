<?php

defined('JPATH_BASE') or die();

jimport('joomla.form.formfield');
class JFormFieldGetInfo extends JFormField {

	var $type = 'getInfo';

	protected function getLabel() {
		$html = '<a target="_blank" href="https://express-pay.by"><img src="/plugins/vmpayment/erip_expresspay/assets/images/erip_expresspay_big.png" width="270" height="91" alt="exspress-pay.by" title="express-pay.by"></a>';

		return $html;
	}

	protected function getInput() {
		$html = '<div style="width: 700px; margin-top: 14px;">' . JText::_('VMPAYMENT_DESCRIPTION') . '</div>';

		return $html;
	}

}