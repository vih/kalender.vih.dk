<?php
require_once 'config.local.php';
require_once 'konstrukt/konstrukt.inc.php';
require_once 'facebook.php';
require_once 'bucket.inc.php';
require_once 'pdoext/connection.inc.php';
require_once 'pdoext/query.inc.php';
require_once 'pdoext/tablegateway.php';
require_once 'Ilib/ClassLoader.php';
require_once 'fpdf/fpdf.php';

require_once 'Monthly.php';
set_error_handler('k_exceptions_error_handler');
spl_autoload_register('k_autoload');
date_default_timezone_set('Europe/Copenhagen');
setlocale(LC_ALL, "da_DK");

class VIH_Calendar_TableGateway extends pdoext_TableGateway
{
    function __construct(pdoext_Connection $pdo)
    {
        parent::__construct('calendar', $pdo);
    }
}

class k_PdfResponse extends k_ComplexResponse
{
    function contentType()
    {
        return 'application/pdf';
    }

    protected function marshal()
    {
        return $this->content;
    }
}

class VIH_Calendar_IdentityLoader extends k_BasicHttpIdentityLoader
{
    function selectUser($username, $password)
    {
        $users = array(
      		$GLOBALS['user'] => $GLOBALS['password']
        );
        if (isset($users[$username]) && $users[$username] == $password) {
            return new k_AuthenticatedUser($username);
        }
    }
}

class VIH_Calender_ApplicationFactory
{
    protected $appapikey;
    protected $appsecret;

    function __construct()
    {
        $this->appapikey = $GLOBALS['facebook_appapikey'];
        $this->appsecret = $GLOBALS['facebook_appsecret'];
        $this->app_id = $GLOBALS['facebook_app_id'];
    }

    function new_k_TemplateFactory($c)
    {
        return new k_DefaultTemplateFactory(dirname(__FILE__) . '/templates/');
    }

    function new_Zend_Gdata_Calendar($c)
    {
        return new Zend_Gdata_Calendar();
    }

    function new_PDO($c)
    {
        require_once 'pdoext/connection.inc.php';
        $db = new pdoext_Connection("sqlite:../calendar.sqlite", "root", "");
        $schema = file_get_contents("../calendar.ddl");
        try {
            $db->query($schema);
        } catch (PDOException $ex) {
            // throw new Exception('Schema could not be created');
        }

        return $db;
    }

    function new_VIH_Calendar_TableGateway($c)
    {
        return new VIH_Calendar_TableGateway($this->new_PDO($c));
    }

    function new_Facebook($c)
    {
        $credentials = array(
        'appId'  => $this->app_id,
        'secret' => $this->appsecret,
        'cookie' => true);

        return new Facebook($credentials);
    }

    function new_Zend_Cache_Core($c)
    {
        $frontendOptions = array(
           'lifetime' => 7200, // cache lifetime of 2 hours
           'automatic_serialization' => true
        );

        $backendOptions = array(
            'cache_dir' => dirname(__FILE__) . '/cache/' // Directory where to put the cache files
        );

        // getting a Zend_Cache_Core object
        $cache = Zend_Cache::factory('Core', 'File', $frontendOptions, $backendOptions);

        return $cache;
    }

    function new_FPDF()
    {
        return new FPDF('L','mm','A4');
    }
}

class VIH_Calender_Document extends k_Document
{
    protected $options = array();

    function addOption($identifier, $url)
    {
        $this->options[$identifier] = $url;
    }

    function options()
    {
        return $this->options;
    }
}

class DanishLanguage implements k_Language
{
    function name()
    {
        return 'Danish';
    }

    function isoCode()
    {
        return 'da';
    }
}

class MyLanguageLoader implements k_LanguageLoader
{
    function load(k_Context $context)
    {
        return new DanishLanguage();
    }
}

class SimpleTranslatorLoader implements k_TranslatorLoader
{
    function load(k_Context $context)
    {
        // Default to English
        $phrases = array(
          'Monday' => 'mandag',
          'Tuesday' => 'tirsdag',
          'Wednesday' => 'onsdag',
          'Thursday' => 'torsdag',
          'Friday' => 'fredag',
          'Saturday' => 'lørdag',
          'Sunday' => 'søndag',
          'January' => 'januar',
          'February' => 'februar',
          'March' => 'marts',
          'April' => 'april',
          'May' => 'maj',
          'June' => 'juni',
          'July' => 'juli',
          'August' => 'august',
          'September' => 'september',
          'October' => 'oktober',
          'November' => 'november',
          'December' => 'december',
          '1' => 'januar',
          '2' => 'februar',
          '3' => 'marts',
          '4' => 'april',
          '5' => 'maj',
          '6' => 'juni',
          '7' => 'juli',
          '8' => 'august',
          '9' => 'september',
          '10' => 'oktober',
          '11' => 'november',
          '12' => 'december'

          );
          return new Intraface_Calender_Translator($phrases);
    }
}

class Intraface_Calender_Translator implements k_Translator
{
    protected $phrases;

    function __construct($phrases)
    {
        $this->phrases = $phrases;
    }

    function translate($phrase, k_Language $language = null)
    {
        return isset($this->phrases[$phrase]) ? $this->phrases[$phrase] : $phrase;
    }
}

class VIH_Calender_Index extends VIH_Intranet_Controller_Calendar_Index
{
    protected $template;

    function __construct(k_TemplateFactory $template)
    {
        $this->template = $template;
    }

    function dispatch()
    {
        if ($this->query('auth_token') AND $this->query('next')) {
            $this->session()->set('facebook_auth_token', $this->query('auth_token'));
            return new k_SeeOther($this->query('next'));
        }
        return parent::dispatch();
    }

    function map($name)
    {
        if ($name == 'foredrag') {
            return 'VIH_Calender_Foredrag';
        }
    }

    function execute()
    {
        return $this->wrap(parent::execute());
    }

    function wrapHtml($content)
    {
        $tpl = $this->template->create('content');
        return $tpl->render($this, array('content' => $content, 'title' => $this->document->title(), 'options' => $this->document->options()));
    }
}

class VIH_Calender_Foredrag extends k_Component
{
    protected $gdata;
    protected $template;
    public $timezone;
    protected $events;
    protected $event_gateway;
    protected $cache;
    protected $pdf;

    function __construct(FPDF $pdf, Zend_Cache_Core $cache, VIH_Calendar_TableGateway $gateway, Zend_Gdata_Calendar $gdata, k_TemplateFactory $template)
    {
        $this->gdata = $gdata;
        $this->template = $template;
        $this->event_gateway = $gateway;
        $this->cache = $cache;
        $this->pdf = $pdf;
    }

    function map($name)
    {
        return 'VIH_Calender_Show';
    }

    function getTimeZone()
    {
        //$this->timezone = $this->ical->getTimeZone();
        return $this->timezone = 'Europe/Berlin';
    }

    function getCalendarId()
    {
        return '8qle53tc1ogekrkuf3ndam51as@group.calendar.google.com';
    }

    function getEvents()
    {
        if (!$this->events = $this->cache->load(md5($this->getCalendarId()))) {
            $query = $this->gdata->newEventQuery();
            $query->setUser($this->getCalendarId());
            $query->setOrderby('starttime');
            $query->setSortOrder('ascending');
            $query->setProjection('full');
            $query->setFutureevents('true');

            try {
                $this->events = $this->gdata->getCalendarEventFeed($query);
            } catch (Zend_Gdata_App_Exception $e) {
                throw new Exception("Error: " . $e->getMessage());
            }

            $this->cache->save($this->events, md5($this->getCalendarId()));
        }
        return $this->events;
    }

    function renderHtml()
    {
        $this->document->setTitle($this->getEvents()->getTitle());

        $data = array(
            'events' => $this->getEvents()
        );

        $tpl = $this->template->create('simple');
        return $tpl->render($this, $data);
    }

    function renderPdf()
    {
        $this->pdf->SetTitle(utf8_decode($this->getEvents()->getTitle()));
        $this->pdf->SetSubject(utf8_decode($this->getEvents()->getSubtitle()));
        $this->pdf->SetAuthor('Lars Olesen');
        $this->pdf->SetAutoPageBreak(false);

        $this->addFrontpage();

        foreach ($this->getEvents() as $event) {
            $this->addEventPage($event);
        }
        return $this->pdf->Output();
    }

    function setPdf($pdf)
    {
        $this->pdf = $pdf;
    }

    function getPdf()
    {
        return $this->pdf;
    }

    function addFrontpage()
    {
        $this->pdf->addPage();

        $this->pdf->Image('logo.jpg',100,55,100,0,'', 'http://vih.dk/');

        $this->pdf->SetFont('Helvetica', 'B', 26);
        $this->pdf->setTextColor(0, 0, 0);
        $this->pdf->Cell(0, 200,utf8_decode('Foredrag på Vejle Idrætshøjskole'), null, 2, 'C', false);

        $this->pdf->addPage();
        $this->pdf->SetFont('Helvetica', 'B', 26);
        $this->pdf->setTextColor(255, 255, 255);
        $this->pdf->Cell(0, 22,utf8_decode('Foredrag på Vejle Idrætshøjskole'), null, 2, 'C', true);

        $this->pdf->SetFont('Helvetica', null, 18);
        $this->pdf->setTextColor(0, 0, 0);
        $this->pdf->setY(40);
        $this->pdf->MultiCell(275, 10, utf8_decode($this->getEvents()->getSubtitle()), 0);

        $this->pdf->Image('logo.jpg',235,180,50,0,'', 'http://vih.dk/');

    }

    function addEventPage($event)
    {
        $this->pdf->addPage();
        $this->pdf->SetFont('Helvetica', 'B', 26);
        $this->pdf->setTextColor(255, 255, 255);
        $this->pdf->Cell(0, 22,utf8_decode($event->title), null, 2, 'C', true);

        $this->pdf->SetFont('Helvetica', null, 18);
        $this->pdf->setTextColor(0, 0, 0);
        $this->pdf->setY(40);

        if (isset($event->content)) {
            $this->pdf->MultiCell(200, 10, utf8_decode($event->content), 0);
        } else {
            $this->pdf->MultiCell(200, 10, utf8_decode('Beskrivelse følger senere. '), 0);
        }

        $this->pdf->Line(10, 165, 220, 165);
        $this->pdf->Line(220, 40, 220, 220);

        $filename = $this->getImageNameFromEventId($this->getEventIdFromEvent($event));

        if ($filename) {
            $this->pdf->Image('uploads/' . $filename, 225, 40, 60, 0, '');
        }

        $image_url = "http://chart.apis.google.com/chart?cht=qr&chs=100x100&chl=http://kalender.vih.dk/foredrag/" . $this->getEventIdFromEvent($event);

        $ch = curl_init();
        $timeout = 0;
        curl_setopt ($ch, CURLOPT_URL, $image_url);
        curl_setopt ($ch, CURLOPT_CONNECTTIMEOUT, $timeout);

        // Getting binary data
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_BINARYTRANSFER, 1);

        $image = curl_exec($ch);

        curl_close($ch);

        $f = fopen(dirname(__FILE__) . '/barcodes/'.$this->getEventIdFromEvent($event).'.png', 'w');
        fwrite($f, $image);
        fclose($f);

        $this->pdf->Image('barcodes/' . $this->getEventIdFromEvent($event) . '.png', 235, 135, 45, 0, '');

        $this->pdf->Image('logo.jpg', 235, 180, 50, 0, '', 'http://vih.dk/');

        $this->pdf->setY(168);
        $this->pdf->SetFont('Helvetica', null, 14);

        $this->pdf->MultiCell(200, 12, 'Pris og sted: ' . utf8_decode(trim($event->where[0]->valueString)));

        $start_date = new DateTime(date('Y-m-d H:i:s', strtotime($event->when[0]->startTime)), new DateTimeZone($this->getTimeZone()));
        $end_date = new DateTime(date('Y-m-d H:i:s', strtotime($event->when[0]->endTime)), new DateTimeZone($this->getTimeZone()));
        $this->pdf->setY(174);
        $date = ucfirst($this->t($start_date->format('l'))) . ', ' . $start_date->format('j.') . $this->t($start_date->format('F')) . ', ' . $start_date->format('Y H:i') . '-' . $end_date->format('H:i');
        $this->pdf->Write(14, 'Tid: ' . utf8_decode($date));
        $this->pdf->Ln();
    }

    function getImageNameFromEventId($id)
    {
        $record = $this->event_gateway->fetch(array('gcal_event_id' => $id, 'parameter' => 'image'));
        if (!empty($record)) {
            return $record['key'];
        } else {
            return null;
        }
    }

    function getEventIdFromEvent($event)
    {
        return substr($event->id, strrpos($event->id, '/')+1);
    }
}

class VIH_Calender_Show extends k_Component
{
    protected $gdata;
    protected $template;
    protected $event;
    protected $encoding = 'UTF-8';
    protected $form;
    protected $event_gateway;
    protected $pdf;
    protected $cache;

    function __construct(FPDF $pdf, Zend_Cache_Core $cache, VIH_Calendar_TableGateway $gateway, Zend_Gdata_Calendar $gdata, k_TemplateFactory $template)
    {
        $this->gdata = $gdata;
        $this->template = $template;
        $this->event_gateway = $gateway;
        $this->cache = $cache;
        $this->pdf = $pdf;
    }

    function map($name)
    {
        if ($name == 'facebook') {
            return 'VIH_Calender_Facebook';
        } elseif ($name == 'kultunaut') {
            return 'VIH_Calender_Kultunaut';
        }
    }

    function dispatch()
    {
        try {
            $this->event = $this->getEvent();
        } catch (Exception $e) {
            throw new Exception("Error: " . $e->getMessage());
        }
        return parent::dispatch();
    }

    function getPublishers()
    {
        $publishers = array(
            'facebook' => array(
                'image' => $this->url('/facebook.png'),
                'name' => 'facebook',
                'event_url' => 'http://www.facebook.com/event.php?eid='
                ),
            'kultunaut' => array(
                'image' => $this->url('/kultunaut.jpg'),
                'name' => 'kultunaut',
                'event_url' => 'http://www.kultunaut.dk/perl/anmeld/type-nynaut/nr-'
                )
                );

                foreach ($publishers as $key => $publisher) {
                    $record = $this->event_gateway->fetch(array('gcal_event_id' => $this->getEventId(), "parameter" => $key . '_event_id'));
                    if (!empty($record)) {
                        $publishers[$key]['status'] = 'Publiseret';
                        $publishers[$key]['event_url'] = $publishers[$key]['event_url'] . $record['key'];
                    } else {
                        $publishers[$key]['status'] = 'Ikke publiseret';
                        $publishers[$key]['event_url'] = $this->url($key);
                    }
                }

                return $publishers;
    }

    function renderPdf()
    {
        $this->pdf->SetTitle(utf8_decode($this->getEvent()->title));
        $this->pdf->SetSubject(utf8_decode($this->getEvent()->summary));
        $this->pdf->SetAuthor('Lars Olesen');
        $this->pdf->SetAutoPageBreak(false);

        $this->context->setPdf($this->pdf);
        $this->context->addEventPage($this->getEvent());
        return $this->context->getPdf()->Output();
    }

    function renderHtml()
    {
        $this->document->setTitle($this->event->title);
        $this->document->addOption('Tilbage', $this->url('../'));

        $tpl = $this->template->create('show');
        $content = $tpl->render($this, array('event' => $this->event));

        if ($this->identity()->anonymous()) {
            return $content;
        }

        $tpl = $this->template->create('publishers');
        return $content . $tpl->render($this, array('publishers' => $this->getPublishers()));
    }

    function getImageName()
    {
        $record = $this->event_gateway->fetch(array('gcal_event_id' => $this->getEventId(), 'parameter' => 'image'));

        if (!empty($record)) {
            return $image = $record['key'];
        } else {
            return null;
        }
    }

    function getForm()
    {
        if (is_object($this->form)) {
            return $this->form;
        }

        $this->form = new HTML_QuickForm(null, 'POST', $this->url(null, array($this->subview())));
        $this->form->addElement('file', 'file', 'Billede');
        $this->form->addElement('submit', null, 'Upload');

        return $this->form;
    }

    function renderHtmlUpload()
    {
        // @todo does not work with this. What is wrong!
        if ($this->identity()->anonymous()) {
            throw new k_NotAuthorized();
        }

        $this->document->setTitle('Upload billede til ' . $this->getEvent()->title);
        return $this->getForm()->toHTML();
    }

    function postMultipart()
    {
        if ($this->identity()->anonymous()) {
            throw new k_NotAuthorized();
        }

        if ($this->getForm()->validate()) {

            // @todo should be replaced by the construct wrapper
            // $adapter = new k_adapter_DefaultUploadedFileAccess();
            // $adapter->copy($this->body('file'), 'uploads/');

            // upload picture
            $upload = new HTTP_Upload("en");
            $file = $upload->getFiles("file");

            // @todo gives the wrong permissions when uploading
            if ($file->isValid()) {
                $moved = $file->moveTo('uploads/');
                if (PEAR::isError($moved)) {
                    throw new Exception($moved->getMessage());
                }
            } elseif ($file->isMissing()) {
                throw new Exception("No file was provided.");
            } elseif ($file->isError()) {
                throw new Exception($file->errorMsg());
            }

            $record = $this->event_gateway->fetch(array('gcal_event_id' => $this->getEventId(), "parameter" => 'image'));

            if (empty($record)) {
                $this->event_gateway->insert(
                array(
                    'gcal_event_id' => $this->getEventId(),
                    "parameter" => 'image',
                    'key' => $file->getProp('name')));
            } else {
                $this->event_gateway->update(
                array('key' => $file->getProp('name')),
                array('gcal_event_id' => $this->getEventId(),
                      "parameter" => 'image'));

            }
            return new k_SeeOther($this->url());
        }

        return $this->render();
    }

    function getEvent()
    {
        if (!$this->event = $this->cache->load(md5($this->name()))) {
            $query = $this->gdata->newEventQuery();
            $query->setUser($this->context->getCalendarId());
            $query->setVisibility('public');
            $query->setProjection('full');
            $query->setEvent($this->name());
            $this->event = $this->gdata->getCalendarEventEntry($query);
            $this->cache->save($this->event, md5($this->name()));
        }

        return $this->event;
    }

    function getTimeZone()
    {
        return $this->context->getTimeZone();
    }

    function getDateStart()
    {
        return new DateTime(date('Y-m-d H:i:s', strtotime($this->getEvent()->when[0]->startTime)), new DateTimeZone($this->getTimeZone()));
    }

    function getDateEnd()
    {
        return new DateTime(date('Y-m-d H:i:s', strtotime($this->getEvent()->when[0]->endTime)), new DateTimeZone($this->getTimeZone()));
    }

    function getEventId()
    {
        return substr($this->getEvent()->id, strrpos($this->getEvent()->id, '/')+1);
    }
}

class VIH_Calendar_Publish extends k_Component
{
    function dispatch()
    {
        if ($this->identity()->anonymous()) {
            throw new k_NotAuthorized();
        }
        return parent::dispatch();
    }
}

class VIH_Calender_Facebook extends VIH_Calendar_Publish
{
    protected $facebook;
    protected $event_gateway;
    protected $template;
    protected $user_id;
    protected $session;

    function __construct(Facebook $facebook, VIH_Calendar_TableGateway $gateway, k_TemplateFactory $template)
    {
        $this->event_gateway = $gateway;
        $this->template = $template;
        $this->facebook = $facebook;
        $me = null;
        $this->session = $this->facebook->getSession();

        // Session based API call.
        if ($this->session) {
            try {
                $this->user_id = $facebook->getUser();
                $me = $facebook->api('/me');
            } catch (FacebookApiException $e) {
                // void
            }
        }

    }

    function isPublished()
    {
        $record = $this->event_gateway->fetch(array('gcal_event_id' => $this->context->getEventId(), "parameter" => 'facebook_event_id'));

        if (!empty($record)) {
            return true;
        }
        return false;
    }

    function renderHtml()
    {
        if (!$this->session) {
            $this->document->setTitle('Login to Facebook');

            return '<a href="'.$this->facebook->getLoginUrl(array('req_perms' => 'create_event', 'display' => 'popup')).'">Login</a>';
        }
        $this->document->setTitle('Facebook');

        $tpl = $this->template->create('facebook');
        return new k_HttpResponse(200, $tpl->render($this), true);
    }

    function postForm()
    {
        if (!$this->session) {
            $this->document->setTitle('Login to Facebook');

            return ('No session available. <a href="'.$this->facebook->getLoginUrl(array('req_perms' => 'create_event', 'display' => 'popup')).'">Login</a>');
        }

        $summary = $this->context->getEvent()->getTitle()->getText();
        $short_description = $this->context->getEvent()->getContent()->getText();
        //$long_description = 'MultiidrÃÂ¦tsforeningen VIMI fortÃÂ¦ller hvad de byder pÃÂ¥ og laver en times spinning med pedellen.';

        $start_date_day = $this->context->getDateStart()->format('d');
        $start_date_month = $this->context->getDateStart()->format('m');
        $start_date_year = $this->context->getDateStart()->format('Y');
        $start_time_hour = $this->context->getDateStart()->format('H');
        $start_time_min = $this->context->getDateStart()->format('i');

        $end_date_day = $this->context->getDateEnd()->format('d');
        $end_date_month = $this->context->getDateEnd()->format('m');
        $end_date_year= $this->context->getDateEnd()->format('Y');
        $end_time_hour = $this->context->getDateEnd()->format('H');
        $end_time_min = $this->context->getDateEnd()->format('i');

        // @hack to provide the correct timestamp
        $timestamp_add = 16;
        $start_time_hour = $start_time_hour + $timestamp_add;
        $end_time_hour = $end_time_hour + $timestamp_add;
        // @hack end

        $genre = 'Foredrag';
        $group = 'Alle'; // Group
        $price = 0;
        $homepage = 'http://vih.dk';
        $email = 'lars@vih.dk';

        $start_time = mktime($start_time_hour, $start_time_min, 00, $start_date_month, $start_date_day, $start_date_year);
        $end_time = mktime($end_time_hour, $end_time_min, 00, $end_date_month, $end_date_day, $end_date_year);

        $page_id = 93365171887;
        $host = 'Vejle IdrÃ¦tshÃ¸jskole';

        $category = 'Education';
        $sub_category = 'Lecture';
        $location = 'Vejle Idrætshøjskole';
        $tagline = 'Alle er velkomne';

        // categories on http://wiki.developers.facebook.com/index.php/Event_Categories
        //'Sports',
        //'Sporting Practice',

        $data = array(
            'name' => $summary,
            'category' => $category,
            'subcategory' => $sub_category,
            'host' => $host,
            'location' => $location,
            'start_time' => $start_time,
            'end_time' => $end_time,
            'street' => 'Ørnebjergvej 28',
            // 'city' => 'Vejle',
            'phone' => '',
            'email' => $email,
            'description' => $short_description,
            'privacy_type', // OPEN, CLOSED, SECRET
            'tagline' => $tagline,
            'page_id' => $page_id
        );


        try {
            $param  =   array(
            'method' => 'events.create',
            'uids' => $this->user_id,
            'event_info' => json_encode($data),
            'callback'  => '' );

            $event_id = $this->facebook->api($param);
        } catch (Exception $e){
            return 'Error message: '.$e->getMessage().' Error code:'.$e->getCode();
        }

        if ($this->isPublished()) {
            if ($this->body('force')) {
                $record = $this->event_gateway->fetch(array('gcal_event_id' => $this->context->getEventId(), "parameter" => 'facebook_event_id'));

                $this->event_gateway->update(
                array(
                		'gcal_event_id' => $this->context->getEventId(),
            			"parameter" => 'facebook_event_id',
                		'key' => $event_id), array('id'=>$record['id']));

                return new k_SeeOther($this->context->url(null, array('flare' => 'event republished as event id ' . $event_id)));

            }
            throw new Exception('Event has already been published');
        }

        /*
         $record = $this->event_gateway->fetch(array('gcal_event_id' => $this->context->getEventId(), "parameter" => 'facebook_event_id'));

         if (!empty($record)) {
         throw new Exception('Event has already been published');
         }
         */

        $this->event_gateway->insert(
        array(
                'gcal_event_id' => $this->context->getEventId(),
            	"parameter" => 'facebook_event_id',
                'key' => $event_id));

        return new k_SeeOther($this->context->url(null, array('flare' => 'event created as event id ' . $event_id)));
    }
}

class VIH_Calender_Kultunaut extends VIH_Calendar_Publish
{
    protected $event_gateway;

    function __construct(VIH_Calendar_TableGateway $gateway)
    {
        $this->event_gateway = $gateway;
    }

    function renderHtml()
    {
        $this->document->setTitle('Publiser "'. $this->context->getEvent()->title . '" til kultunaut');

        $record = $this->event_gateway->fetch(array('gcal_event_id' => $this->context->getEventId(), "parameter" => 'kultunaut_event_id'));

        if (!empty($record)) {
            $link = 'http://www.kultunaut.dk/perl/anmeld/type-nynaut/ny-';
            return '<p><a href="'.$link.$record['key'].'">Indtast rettelser</a></p>';
        } else {
            return '<form action="'.$this->url().'" method="post"><input type="submit" value="Publiser til kultunaut"></form>';
        }
    }

    function postForm()
    {
        require_once 'HTTP/Request2.php';
        $timestamp = date('YmdHis');
        $request = new HTTP_Request2('http://www.kultunaut.dk/perl/arradd/type-nynaut/timestamp-' . $timestamp, HTTP_Request2::METHOD_POST);

        $summary = $this->context->getEvent()->title;
        $short_description = $this->context->getEvent()->summary;
        $long_description = $this->context->getEvent()->content;
        $start_date_day = $this->context->getDateStart()->format('d');
        $start_date_month = $this->context->getDateStart()->format('n');
        $start_date_year = $this->context->getDateStart()->format('Y');
        $end_date_day = $this->context->getDateEnd()->format('d');
        $end_date_month = $this->context->getDateEnd()->format('n');
        $start_date_year = $this->context->getDateEnd()->format('Y');
        if ($this->context->getDateStart()->format('i') == '00') {
            $time = 'kl. ' . $this->context->getDateStart()->format('H');
        } else {
            $time = 'kl. ' . $this->context->getDateStart()->format('H') . '.' . $this->context->getDateStart()->format('i');
        }
        $genre = 'Foredrag';
        $group = 'Alle'; // MÃ¥lgruppe
        $price = 1;
        $homepage = 'http://kalender.vih.dk' . $this->url('../');
        $email = 'lars@vih.dk';

        $data = array(
        	'side' => 'arr',
            'ArrKunstner' => $summary,
            'ArrBeskrivelse' => $short_description,
        	'ArrLangBeskriv' => $long_description,
        	'ArrStartday' => $start_date_day,
        	'ArrStartmonth' => $start_date_month,
        	'ArrStartyear' => $start_date_year,
            'ArrSlutday' => $end_date_day,
        	'ArrSlutmonth' => $end_date_month,
        	'ArrSlutyear' => $start_date_year,
        	'ArrTidspunkt' => $time,
        	'ArrUGenre' => $genre,
        	'ArrMaalgruppe' => $group,
            'ArrPris' => 'Ukendt pris',
        // 'ArrPrisEntre' => $price,
        	'ArrHomepage' => $homepage,
        	'ArrEmail' => $email
        );

        $data = array_map('utf8_decode', $data);

        $request->addPostParameter($data);

        $response = $request->send();

        // @todo do some error checking

        $timestamp = date('YmdHis');
        $request = new HTTP_Request2('http://www.kultunaut.dk/perl/arradd/type-nynaut/timestamp-' . $timestamp, HTTP_Request2::METHOD_POST);

        $data['side'] = 'sted';
        $data['StedNr'] = $GLOBALS['kultunaut_place']; // Vejle Idrætshøjskole
        $data['SkiftArrang'] = 'no';
        $data['adress'] = utf8_decode("Vejle Idrætshøjskole\nØrnebjergvej 28\n7100 Vejle");

        $request->addPostParameter($data);

        $response = $request->send();

        $body = $response->getBody();

        // @todo do some error checking

        $xpath = new DOMXPath(@DOMDocument::loadHTML($body));

        $event_id = $xpath->query("//input[@type='hidden' and @name='ArrNr']/@value")->item(0)->nodeValue; #4485217

        $record = $this->event_gateway->fetch(array('gcal_event_id' => $this->context->getEventId(), "parameter" => 'kultunaut_event_id'));

        if (!empty($record)) {
            throw new Exception('Event has already been published');
        }

        $this->event_gateway->insert(
        array(
                'gcal_event_id' => $this->context->getEventId(),
            	"parameter" => 'kultunaut_event_id',
                'key' => $event_id));

        $data['side'] = 'godkend';
        $data['ArrNr'] = $event_id;

        $request = new HTTP_Request2('http://www.kultunaut.dk/perl/arradd/type-nynaut/timestamp-' . $timestamp . '/tak', HTTP_Request2::METHOD_POST);
        $request->addPostParameter($data);
        $response = $request->send();

        if (!strpos($response->getBody(), 'TAK FOR INDTASTNINGEN')) {
            throw new Exception('Something went wrong on confirm');
        }

        return new k_SeeOther($this->context->url(null, array('flare' => 'Event created: ' . $event_id)));
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME']) == __FILE__) {
    k()
    ->setIdentityLoader(new VIH_Calendar_IdentityLoader())
    ->setLanguageLoader(new MyLanguageLoader())->setTranslatorLoader(new SimpleTranslatorLoader())
    ->setComponentCreator(new k_InjectorAdapter(new bucket_Container(new VIH_Calender_ApplicationFactory), new VIH_Calender_Document))
    ->run('VIH_Calender_Index')
    ->out();
}
