<?php

/**
 * @package   JD Simple Contact Form
 * @author    JoomDev https://www.joomdev.com
 * @copyright Copyright (C) 2009 - 2018 JoomDev.
 * @license http://www.gnu.org/licenses/gpl-2.0.html GNU/GPLv2 or Later
 */
// no direct access
defined('_JEXEC') or die;

class ModJDSimpleContactFormHelper {

   public static function renderForm($params, $module) {
      $fields = $params->get('fields', []);
      foreach ($fields as $field) {
         $field->id = \JFilterOutput::stringURLSafe('jdscf-' . $module->id . '-' . $field->name);
         self::renderField($field, $module);
      }
   }

   public static function renderField($field, $module) {
      $label = new JLayoutFile('label', JPATH_SITE . '/modules/mod_jdsimplecontactform/layouts');
      $field_layout = self::getFieldLayout($field->type);
      $input = new JLayoutFile('fields.' . $field_layout, JPATH_SITE . '/modules/mod_jdsimplecontactform/layouts');
      $layout = new JLayoutFile('field', JPATH_SITE . '/modules/mod_jdsimplecontactform/layouts');
      if ($field->type == 'checkbox') {
         $field->show_label = 0;
      }
      echo $layout->render(['field' => $field, 'label' => $label->render(['field' => $field]), 'input' => $input->render(['field' => $field, 'label' => self::getLabelText($field), 'module' => $module]), 'module' => $module]);
   }

   public static function getOptions($options) {
      $options = explode("\n", $options);
      $array = [];
      foreach ($options as $option) {
         if (!empty($option)) {
            $array[] = ['text' => $option, 'value' => $option];
         }
      }
      return $array;
   }

   public static function getLabelText($field) {
      $label = $field->label;
      if (empty($label)) {
         $label = ucfirst($field->name);
      } else {
         $label = JText::_($label);
      }
      return $label;
   }

   public static function getFieldLayout($type) {
      $return = '';
      if (file_exists(JPATH_SITE . '/modules/mod_jdsimplecontactform/layouts/fields/' . $type . '.php')) {
         $return = $type;
      } else {
         $return = 'text';
      }
      return $return;
   }

   public static function submitForm($ajax = false) {
      if (!JSession::checkToken()) {
         throw new \Exception(JText::_("JINVALID_TOKEN"));
      }
      if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
         throw new \Exception(JText::_('MOD_JDSCF_BAD_REQUEST'), 400);
      }
      $app = JFactory::getApplication();
      $jinput = $app->input->post;

      $jdscf = $jinput->get('jdscf', [], 'ARRAY');
      $id = $jinput->get('id', [], 'INT');
      $params = self::getModuleParams();

      if ($params->get('captcha', 0)) {
         JPluginHelper::importPlugin('captcha');
         $dispatcher = JEventDispatcher::getInstance();
         $check_captcha = $dispatcher->trigger('onCheckAnswer', $jinput->get('recaptcha_response_field'));
         if (!$check_captcha[0]) {
            throw new \Exception(JText::_('Invalid Captcha'), 0);
         }
      }

      $labels = [];
      foreach ($params->get('fields', []) as $field) {
         $labels[$field->name] = ['label' => self::getLabelText($field), 'type' => $field->type];
      }

      $values = [];
      foreach ($jdscf as $name => $value) {
         $values[$name] = $value;
      }


      $contents = [];
      foreach ($labels as $name => $fld) {
         $value = isset($values[$name]) ? $values[$name] : '';
         if ($fld['type'] == 'checkbox') {
            if (is_array($value)) {
               $value = implode(',', $value);
            }
            $value = empty($value) ? 'unchecked' : 'checked';
         }
         if ($fld['type'] == 'textarea') {
            if ($value) {
               $value = nl2br($value);
            }
         }
         $contents[] = [
             "value" => $value,
             "label" => $fld['label'],
             "name" => $name,
         ];
      }
      if ($params->get('email_template', '') == 'custom') {
         $html = $params->get('email_custom', '');
         $html = self::renderVariables($contents, $html);
      } else {
         $layout = new JLayoutFile('emails.default', JPATH_SITE . '/modules/mod_jdsimplecontactform/layouts');
         $html = $layout->render(['contents' => $contents]);
      }

      // sending mail
      $mailer = JFactory::getMailer();
      $config = JFactory::getConfig();
      $title = $params->get('title', '');
      if (!empty($title)) {
         $title = ' : ' . $title;
      }
      // Sender
      if (!empty($params->get('email_from', ''))) {
         $email_from = $params->get('email_from', '');
         $email_from = self::renderVariables($contents, $email_from);
         if (!filter_var($email_from, FILTER_VALIDATE_EMAIL)) {
            $email_from = $config->get('mailfrom');
         }
      } else {
         $email_from = $config->get('mailfrom');
      }

      if (!empty($params->get('email_name', ''))) {
         $email_name = $params->get('email_name', '');
         $email_name = self::renderVariables($contents, $email_name);
         if (empty($email_name)) {
            $email_name = $config->get('fromname');
         }
      } else {
         $email_name = $config->get('fromname');
      }

      $sender = array($email_from, $email_name);
      $mailer->setSender($sender);

      // Subject
      $email_subject = !empty($params->get('email_subject', '')) ? $params->get('email_subject') : JText::_('MOD_JDSCF_DEFAULT_SUBJECT', $title);
      $email_subject = self::renderVariables($contents, $email_subject);
      $mailer->setSubject($email_subject);

      // Recipient
      $recipients = !empty($params->get('email_to', '')) ? $params->get('email_to') : $config->get('mailfrom');
      $recipients = explode(',', $recipients);
      if (!empty($recipients)) {
         $mailer->addRecipient($recipients);
      }
      // CC
      $cc = !empty($params->get('email_cc', '')) ? $params->get('email_cc') : '';
      $cc = explode(',', $cc);
      if (!empty($cc)) {
         $mailer->addCc($cc);
      }
      // BCC
      $bcc = !empty($params->get('email_bcc', '')) ? $params->get('email_bcc') : '';
      $bcc = explode(',', $bcc);
      if (!empty($bcc)) {
         $mailer->addBcc($bcc);
      }
      $mailer->isHtml(true);
      $mailer->Encoding = 'base64';
      $mailer->setBody($html);
      $send = $mailer->Send();
      if ($send !== true) {
         throw new \Exception(JText::_('MOD_JDSCFEMAIL_SEND_ERROR'));
      }
      $message = $params->get('thankyou_message', '');
      if (empty($message)) {
         $message = JText::_('MOD_JDSCF_THANKYOU_DEFAULT');
      } else {
         $template = $params->get('email_custom', '');
         $message = self::renderVariables($contents, $message);
      }
      $redirect_url = $params->get('redirect_url', '');
      $redirect_url = self::renderVariables($contents, $redirect_url);
      if (!$ajax) {
         $return = !empty($redirect_url) ? $redirect_url : urldecode($jinput->get('returnurl', '', 'RAW'));
         $session = JFactory::getSession();
         if (empty($redirect_url)) {
            $session->set('jdscf-message-' . $id, $message);
         } else {
            $session->set('jdscf-message-' . $id, '');
         }
         $app->redirect($return);
      }
      return ['message' => $message, 'redirect' => $redirect_url];
   }

   public static function renderVariables($variables, $source) {
      foreach ($variables as $content) {
         $value = is_array($content['value']) ? implode(', ', $content['value']) : $content['value'];
         $value = empty($value) ? '' : $value;
         $label = empty($content['label']) ? '' : $content['label'];
         $source = str_replace('{' . $content['name'] . ':label}', $label, $source);
         $source = str_replace('{' . $content['name'] . ':value}', $value, $source);
      }
      return $source;
   }

   public static function getModuleParams() {
      $app = JFactory::getApplication();
      $jinput = $app->input->post;
      $id = $jinput->get('id', 0);
      $params = new JRegistry();

      $db = JFactory::getDbo();
      $query = "SELECT * FROM `#__modules` WHERE `id`='$id'";
      $db->setQuery($query);
      $result = $db->loadObject();
      if (!empty($result)) {
         $params->loadString($result->params, 'JSON');
      } else {
         throw new \Exception(JText::_('MOD_JDSCF_MODULE_NOT_FOUND'), 404);
      }
      return $params;
   }

   public static function submitAjax() {
      try {
         self::submitForm();
      } catch (\Exception $e) {
         $app = JFactory::getApplication();
         $params = self::getModuleParams();
         $jinput = $app->input->post;
         $app->enqueueMessage($e->getMessage(), 'error');
         $redirect_url = $params->get('redirect_url', '');
         $return = !empty($redirect_url) ? $redirect_url : urldecode($jinput->get('returnurl', '', 'RAW'));
         $app->redirect($return);
      }
   }

   public static function submitFormAjax() {
      header('Content-Type: application/json');
      header('Access-Control-Allow-Origin: *');
      $return = array();
      try {
         $data = self::submitForm(true);
         $return['status'] = "success";
         $return['code'] = 200;
         $return['data'] = $data;
      } catch (\Exception $e) {
         $return['status'] = "error";
         $return['code'] = $e->getCode();
         $return['message'] = $e->getMessage();
         $return['line'] = $e->getLine();
         $return['file'] = $e->getFile();
      }
      echo \json_encode($return);
      exit;
   }

   public static function addJS($js, $moduleid) {
      if (!isset($GLOBALS['mod_jdscf_js_' . $moduleid])) {
         $GLOBALS['mod_jdscf_js_' . $moduleid] = [];
      }
      $GLOBALS['mod_jdscf_js_' . $moduleid][] = $js;
   }

   public static function getJS($moduleid) {
      if (!isset($GLOBALS['mod_jdscf_js_' . $moduleid])) {
         return [];
      }
      return $GLOBALS['mod_jdscf_js_' . $moduleid];
   }

}
