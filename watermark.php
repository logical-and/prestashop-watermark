<?php
/*
* 2007-2013 PrestaShop
*
* NOTICE OF LICENSE
*
* This source file is subject to the Academic Free License (AFL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/afl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@prestashop.com so we can send you a copy immediately.
*
* DISCLAIMER
*
* Do not edit or add to this file if you wish to upgrade PrestaShop to newer
* versions in the future. If you wish to customize PrestaShop for your
* needs please refer to http://www.prestashop.com for more information.
*
  @author PrestaShop SA <contact@prestashop.com>
*  @copyright  2007-2012 PrestaShop SA
*  @license    http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*/

if (!defined('_PS_VERSION_'))
	exit;

// Like an autoloader
if (!class_exists('Kohana_Exception')) require_once __DIR__ . '/src/Kohana_Exception.php';
if (!class_exists('Kohana_Image')) require_once __DIR__ . '/src/Kohana_Image.php';
if (!class_exists('Kohana_Image_GD')) require_once __DIR__ . '/src/Kohana_Image_GD.php';

class Watermark extends Module
{
	private $_html = '';
	private $_postErrors = array();
	private $xaligns = array('left', 'middle', 'right');
	private $yaligns = array('top', 'middle', 'bottom');
	private $yAlign;
	private $xAlign;
	private $transparency;
	private $percentproportion;
	private $imageTypes = array();
	private	$watermarkTypes;

	private $maxImageSize = 100000;

	public function __construct()
	{
		$this->name = 'watermark';
		$this->tab = 'administration';
		$this->version = '0.6';
		$this->author = 'MOD by Alberto Suárez,from prestashop module';

		parent::__construct();

		$config = Configuration::getMultiple(array('WATERMARK_TYPES', 'WATERMARK_Y_ALIGN', 'WATERMARK_X_ALIGN', 'WATERMARK_TRANSPARENCY','WATERMARK_PROPORTION'));
		if (!isset($config['WATERMARK_TYPES']))
			$config['WATERMARK_TYPES'] = '';
		$tmp = explode(',', $config['WATERMARK_TYPES']);
		foreach (ImageType::getImagesTypes('products') as $type)
			if (in_array($type['id_image_type'], $tmp))
				$this->imageTypes[] = $type;

		$this->yAlign = isset($config['WATERMARK_Y_ALIGN']) ? $config['WATERMARK_Y_ALIGN'] : '';
		$this->xAlign = isset($config['WATERMARK_X_ALIGN']) ? $config['WATERMARK_X_ALIGN'] : '';
		$this->transparency = isset($config['WATERMARK_TRANSPARENCY']) ? $config['WATERMARK_TRANSPARENCY'] : 60;
		$this->percentproportion = isset($config['WATERMARK_PROPORTION']) ? $config['WATERMARK_PROPORTION'] : 1;

		$this->displayName = $this->l('Watermark');
		$this->description = $this->l('Protect image by a PNG watermark.');
		$this->confirmUninstall = $this->l('Are you sure you want to delete your details ?');
		if (!isset($this->transparency) || !isset($this->xAlign) || !isset($this->yAlign))
			$this->warning = $this->l('Watermark image must be uploaded in order for this module to work correctly.');
	}

	public function install()
	{
		if (!parent::install() || !$this->registerHook('watermark'))
			return false;
		Configuration::updateValue('WATERMARK_TRANSPARENCY', 60);
		Configuration::updateValue('WATERMARK_Y_ALIGN', 'bottom');
		Configuration::updateValue('WATERMARK_X_ALIGN', 'right');
		Configuration::updateValue('WATERMARK_PROPORTION', 0.5);
		return true;
	}

	public function uninstall()
	{
		return (parent::uninstall()
			&& Configuration::deleteByName('WATERMARK_TYPES')
			&& Configuration::deleteByName('WATERMARK_TRANSPARENCY')
			&& Configuration::deleteByName('WATERMARK_PROPORTION')
			&& Configuration::deleteByName('WATERMARK_Y_ALIGN')
			&& Configuration::deleteByName('WATERMARK_X_ALIGN'));
	}

	private function _postValidation()
	{
		$yalign = Tools::getValue('yalign');
		$xalign = Tools::getValue('xalign');
		$transparency = (int)(Tools::getValue('transparency'));
		$percentproportion = Tools::getValue('percentproportion');
		$image_types = Tools::getValue('image_types');

		if (empty($transparency))
			$this->_postErrors[] = $this->l('Transparency required.');
		elseif ($transparency < 0 || $transparency > 100)
			$this->_postErrors[] = $this->l('Transparency is not in allowed range.');

		if (empty($percentproportion))
			$this->_postErrors[] = $this->l('Proportion required. (from 0 to 1)');
		elseif ($percentproportion < 0.1 || $percentproportion > 1)
			$this->_postErrors[] = $this->l('Proportion is not in allowed range. (from 0 to 1)');

		if (empty($yalign))
			$this->_postErrors[] = $this->l('Y-Align is required.');
		elseif (!in_array($yalign, $this->yaligns))
			$this->_postErrors[] = $this->l('Y-Align is not in allowed range.');

		if (empty($xalign))
			$this->_postErrors[] = $this->l('X-Align is required.');
		elseif (!in_array($xalign, $this->xaligns))
			$this->_postErrors[] = $this->l('X-Align is not in allowed range.');
		if (empty($image_types))
			$this->_postErrors[] = $this->l('At least one image type is required.');

		if (isset($_FILES['PS_WATERMARK']['tmp_name']) && !empty($_FILES['PS_WATERMARK']['tmp_name']))
		{
			if (!ImageManager::isRealImage($_FILES['PS_WATERMARK']['tmp_name'], $_FILES['PS_WATERMARK']['type'], array('image/png')))
				$this->_postErrors[] = $this->l('Image must be PNG format.');
		}

		return !count($this->_postErrors) ? true : false;
	}

	private function _postProcess()
	{
		Configuration::updateValue('WATERMARK_TYPES', implode(',', Tools::getValue('image_types')));
		Configuration::updateValue('WATERMARK_Y_ALIGN', Tools::getValue('yalign'));
		Configuration::updateValue('WATERMARK_X_ALIGN', Tools::getValue('xalign'));
		Configuration::updateValue('WATERMARK_TRANSPARENCY', Tools::getValue('transparency'));
		Configuration::updateValue('WATERMARK_PROPORTION', Tools::getValue('percentproportion'));

		if (Shop::getContext() == Shop::CONTEXT_SHOP)
			$str_shop = '-'.(int)$this->context->shop->id;
		else
			$str_shop = '';
		//submited watermark
		if (isset($_FILES['PS_WATERMARK']) && !empty($_FILES['PS_WATERMARK']['tmp_name']))
		{
			/* Check watermark validity */
			if ($error = ImageManager::validateUpload($_FILES['PS_WATERMARK']))
				$this->_errors[] = $error;
			/* Copy new watermark */
			elseif (!copy($_FILES['PS_WATERMARK']['tmp_name'], dirname(__FILE__).'/watermark'.$str_shop.'.png'))
				$this->_errors[] = sprintf($this->l('An error occurred while uploading watermark: %1$s to %2$s'), $_FILES['PS_WATERMARK']['tmp_name'], dirname(__FILE__).'/watermark'.$str_shop.'.png');
		}

		if ($this->_errors)
			foreach ($this->_errors as $error)
				$this->_html .= '<div class="module_error alert error"><img src="../img/admin/warning.gif" alt="'.$this->l('ok').'" /> '.$this->l($error).'</div>';
		else
			$this->_html .= '<div class="conf confirm">'.$this->l('Settings updated').'</div>';
	}

	private function _displayForm()
	{
		$imageTypes = ImageType::getImagesTypes('products');
		$str_shop = '-'.(int)$this->context->shop->id;
		if (Shop::getContext() != Shop::CONTEXT_SHOP || !Tools::file_exists_cache(dirname(__FILE__).'/watermark'.$str_shop.'.png'))
			$str_shop = '';

		$this->_html .=
			'<form action="'.Tools::safeOutput($_SERVER['REQUEST_URI']).'" method="post" enctype="multipart/form-data">
			<fieldset><legend><img src="../modules/'.$this->name.'/logo.gif" />'.$this->l('Watermark details').'</legend>
				<p>'.$this->l('Once you have set up the module, regenerate the images using the "Images" tool in Preferences. However, the watermark will be added automatically to new images.').'</p>
				<table border="0" width="60%" cellpadding="0" cellspacing="0" id="form" style="line-height: 25px;min-width: 800px;">
					<tr>
						<td />
						<td>'.(Tools::file_exists_cache(dirname(__FILE__).'/watermark'.$str_shop.'.png') ? '<img src="../modules/'.$this->name.'/watermark'.$str_shop.'.png?t='.time().'" />' : $this->l('No watermark uploaded.')).'</td>
					</tr>
					<tr>
						<td>'.$this->l('Watermark file').'</td>
						<td>
							<input type="file" name="PS_WATERMARK" />
							<p style="color:#7F7F7F; font-size:0.85em; margin:0; padding:0;">'.$this->l('Must be in PNG format').'</p>
						</td>
					</tr>
					<tr>
						<td width="270" style="height: 35px;">'.$this->l('Watermark transparency (0-100)').'</td>
					    <td><input type="text" name="transparency" value="'.(float)Tools::getValue('transparency', Configuration::get('WATERMARK_TRANSPARENCY')).'" style="width: 30px;" /></td>
					</tr>

					<tr>
						<td width="270" style="height: 35px;">'.$this->l('Watermark proportion').' (100%-10%)</td>
					    <td>
					    <select name="percentproportion" style="width: 159px;margin-left: 4px;">
					    	<option  value="'.(float)Tools::getValue('percentproportion', Configuration::get('WATERMARK_PROPORTION')).'"> '.$this->l('Current value:').' '.(100*(float)Tools::getValue('percentproportion', Configuration::get('WATERMARK_PROPORTION'))).'%  </option>
					    	<option value="0.1">10%</value>
					    	<option value="0.25">25%</value>
					    	<option value="0.5">50%</value>
					    	<option value="0.75">75%</value>
					    	<option value="1">100%</value>
					    	</select>
					    </td>
					</tr>
					
					<tr><td width="270" style="height: 35px;">'.$this->l('Watermark X align').'</td>
					    <td>
						<select id="xalign" name = "xalign">';
		foreach ($this->xaligns as $align)
			$this->_html .= '<option value="'.$align.'"'.(Tools::getValue('xalign', Configuration::get('WATERMARK_X_ALIGN')) == $align ? ' selected="selected"' : '' ).'>'.$this->l($align).'</option>';
		$this->_html .= '</select>
					    </td>
					</tr>
					<tr><td width="270" style="height: 35px;">'.$this->l('Watermark Y align').'</td>
					    <td>
						<select id="yalign" name = "yalign">';
		foreach ($this->yaligns as $align)
			$this->_html .= '<option value="'.$align.'"'.(Tools::getValue('yalign', Configuration::get('WATERMARK_Y_ALIGN')) == $align ? ' selected="selected"' : '' ).'>'.$this->l($align).'</option>';
		$this->_html .= '</select>
					    </td>
					</tr>
					<tr><td width="270" style="height: 35px;">'.$this->l('Choose image types for watermark protection.').'</td><td>';
		$selected_types = explode(',', Configuration::get('WATERMARK_TYPES'));
		foreach (ImageType::getImagesTypes('products') as $type)
		{
			$this->_html .= '<label style="float:none; ">
						<input type="checkbox" value="'.$type['id_image_type'].'" name="image_types[]"'.
				(in_array($type['id_image_type'], $selected_types) ? ' checked="checked"' : '').' />&nbsp;<span style="font-weight:bold;">'.$type['name'].'</span>
					    ('.$type['width'].' x '.$type['height'].')</label><br />';
		}
		$this->_html .= '</td></tr>
					<tr><td colspan="2">&nbsp;</td></tr>
					<tr><td colspan="2" align="center"><input class="button" name="btnSubmit" value="'.$this->l('Update settings').'" type="submit" /></td></tr>
				</table>
			</fieldset>
		</form>';
	}

	public function getContent()
	{
		$this->_html = '<h2>'.$this->displayName.'</h2>';
		//$this->_html.='<p>Watermark proportion: '.($this->percentproportion).'</p>';

		$this->_html.= '<div style="background-color: #EBEDF4;border: 1px solid #CCCED7;padding: 10px;-webkit-border-radius: 10px;-moz-border-radius: 10px;border-radius: 10px">';
		$this->_html.='<span>'.$this->l('version 0.6 - 18/08/2013</span>');
		$this->_html.='<h4>'.$this->l('This Prestashop module has been modified and upgraded by').'<a href="http://albertosuarezcaballero.es" style="color:blue" target="_new"> Alberto Suárez.</a></h4>';

		$this->_html.='<table><tr><td>';

		$this->_html.='<form action="https://www.paypal.com/cgi-bin/webscr" method="post" target="_new">
<input type="hidden" name="cmd" value="_s-xclick">
<input type="hidden" name="hosted_button_id" value="VEPY8KFJQ9DVJ">
<input type="image" src="https://www.paypalobjects.com/en_US/ES/i/btn/btn_donateCC_LG.gif" border="0" name="submit" alt="PayPal - The safer, easier way to pay online!">
<img alt="" border="0" src="https://www.paypalobjects.com/es_ES/i/scr/pixel.gif" width="1" height="1">
</form>
';

		$this->_html.='</td><td>';

		$this->_html.='<h4>'.$this->l('* If you find this module useful, please, <u>show your support</u> by making a donation, I will appreciate it very much, and will help me to continue this work').' ;)<br/>'.$this->l('* If you want to check other modules for prestashop, please').' <a href="http://albertosuarezcaballero.es/modules" style="color:blue" target="_new">'.$this->l('visit this site.').'</a>'.'</h4>';

		$this->_html.='</td></tr></table>';
		$this->_html.= '</div>';

		if (Tools::isSubmit('btnSubmit'))
		{
			$this->_postValidation();
			if (!count($this->_postErrors))
				$this->_postProcess();
			else
				foreach ($this->_postErrors as $err)
					$this->_html .= '<div class="alert error">'.$err.'</div>';
		}
		else
			$this->_html .= '<br />';

		$this->_displayForm();

		return $this->_html;
	}

	// Retrocompatibility
	public function hookwatermark($params)
	{
		$this->hookActionWatermark($params);
	}

	public function hookActionWatermark($params)
	{
		$image = new Image($params['id_image']);
		$image->id_product = $params['id_product'];
		$file = _PS_PROD_IMG_DIR_.$image->getExistingImgPath().'-watermark.jpg';

		$str_shop = '-'.(int)$this->context->shop->id;
		if (Shop::getContext() != Shop::CONTEXT_SHOP || !Tools::file_exists_cache(dirname(__FILE__).'/watermark'.$str_shop.'.png'))
			$str_shop = '';

		//first make a watermark image
		$return = $this->watermarkByImage(_PS_PROD_IMG_DIR_.$image->getExistingImgPath().'.jpg', dirname(__FILE__).'/watermark'.$str_shop.'.png', $file, 23, 0, 0, 'right');

		//go through file formats defined for watermark and resize them
		foreach ($this->imageTypes as $imageType)
		{
			$newFile = _PS_PROD_IMG_DIR_.$image->getExistingImgPath().'-'.stripslashes($imageType['name']).'.jpg';
			if (!ImageManager::resize($file, $newFile, (int)$imageType['width'], (int)$imageType['height']))
				$return = false;
		}
		return $return;
	}

	private function watermarkByImage($imagepath, $watermarkpath, $outputpath)
	{
		$originalImage = Kohana_Image::factory($imagepath);
		$watermark     = Kohana_Image::factory($watermarkpath);

		//Proportional resizing
		if ($watermark->width >= $originalImage->width && $watermark->height >= $originalImage->height)
		{
			$varx = $watermark->width / $originalImage->width;
			$vary = $watermark->height / $originalImage->height;

			if ($varx >= $vary)
				$water_resize_factor = $originalImage->width / $watermark->width;
			else
				$water_resize_factor = $originalImage->height / $watermark->height;
		}

		elseif ($watermark->width >= $originalImage->width && $watermark->height < $originalImage->height)
			$water_resize_factor = $originalImage->width / $watermark->width;

		else
		{

			$varx = $watermark->width / $originalImage->width;
			$vary = $watermark->height / $originalImage->height;

			if ($varx >= $vary)
				$water_resize_factor = $originalImage->width / $watermark->width;
			else
				$water_resize_factor = $originalImage->height / $watermark->height;

		}

		$water_resize_factor = $water_resize_factor * ($this->percentproportion);
		$watermark->resize($watermark->width * $water_resize_factor, $watermark->height * $water_resize_factor);

		$Xoffset = $Yoffset = $xpos = $ypos = 0;

		if ($this->xAlign == 'middle')
			$xpos = $originalImage->width / 2 - $watermark->width / 2 + $Xoffset;
		if ($this->xAlign == 'left')
			$xpos = 0 + $Xoffset;
		if ($this->xAlign == 'right')
			$xpos = $originalImage->width - $watermark->width - $Xoffset;
		if ($this->yAlign == 'middle')
			$ypos = $originalImage->height / 2 - $watermark->height / 2 + $Yoffset;
		if ($this->yAlign == 'top')
			$ypos = 0 + $Yoffset;
		if ($this->yAlign == 'bottom')
			$ypos = $originalImage->height - $watermark->height - $Yoffset;

		$originalImage->watermark($watermark, $xpos, $ypos, $this->transparency);

		return $originalImage->save($outputpath, 100);
	}
}

