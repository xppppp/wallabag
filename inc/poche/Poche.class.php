<?php
/**
 * wallabag, self hostable application allowing you to not miss any content anymore
 *
 * @category   wallabag
 * @author     Nicolas Lœuillet <nicolas@loeuillet.org>
 * @copyright  2013
 * @license    http://opensource.org/licenses/MIT see COPYING file
 */

class Poche
{
    /**
     * @var User
     */
    public $user;
    /**
     * @var Database
     */
    public $store;
    /**
     * @var Template
     */
    public $tpl;
    /**
     * @var Language
     */
    public $language;
    /**
     * @var Routing
     */
    public $routing;
    /**
     * @var Messages
     */
    public $messages;
    /**
     * @var Paginator
     */
    public $pagination;
    public $actionOnly;

    public function __construct()
    {
        $this->init();
    }

    private function init()
    {
        Tools::initPhp();

        $pocheUser = Session::getParam('poche_user');

        if ($pocheUser && $pocheUser != array()) {
            $this->user = $pocheUser;
        } else {
            // fake user, just for install & login screens
            $this->user = new User();
            $this->user->setConfig($this->getDefaultConfig());
        }

        $this->pagination   = new Paginator($this->user->getConfigValue('pager'), 'p');
        $this->language     = new Language($this);
        $this->tpl          = new Template($this);
        $this->store        = new Database();
        $this->messages     = new Messages();
        $this->routing      = new Routing($this);
	$this->actionOnly   = false;
    }

    public function run()
    {
        $this->routing->run();
    }

    /**
     * Creates a new user
     */
    public function createNewUser($username, $password, $email = "", $internalRegistration = false)
    {
        Tools::logm('Trying to create a new user...');
        if (!empty($username) && !empty($password)){
            $newUsername = filter_var($username, FILTER_SANITIZE_STRING);
            $email = filter_var($email, FILTER_SANITIZE_STRING);
            if (!$this->store->userExists($newUsername)){
                if ($this->store->install($newUsername, Tools::encodeString($password . $newUsername), $email)) {
                    if ($email != "") { // if email is filled
                        if (SEND_CONFIRMATION_EMAIL && function_exists('mail')) {

                            // if internal registration from config screen
                            $body_internal = _('Hi,') . "\r\n\r\n" . sprintf(_('Someone just created a wallabag account for you on %1$s.'), Tools::getPocheUrl()) . 
                            "\r\n\r\n" . sprintf(_('Your login is %1$s.'), $newUsername) ."\r\n\r\n" .
                            _('Note : The password has been chosen by the person who created your account. Get in touch with that person to know your password and change it as soon as possible') . "\r\n\r\n" .
                            _('Have fun with it !') . "\r\n\r\n" .
                            _('This is an automatically generated message, no one will answer if you respond to it.');
                            
                            // if external (public) registration
                            $body = sprintf(_('Hi, %1$s'), $newUsername) . "\r\n\r\n" . 
                            sprintf(_('You\'ve just created a wallabag account on %1$s.'), Tools::getPocheUrl()) . 
                            "\r\n\r\n" . _("Have fun with it !");

                            $body = $internalRegistration ? $body_internal : $body;

                            $body = wordwrap($body, 70, "\r\n"); // cut lines with more than 70 caracters (MIME standard)
                            if (mail($email, sprintf(_('Your new wallabag account on %1$s'), Tools::getPocheUrl()), $body, 
                                'X-Mailer: PHP/' . phpversion() .  "\r\n" . 
                                'Content-type: text/plain; charset=UTF-8' . "\r\n" .
                                "From: " . $newUsername . "@" . gethostname() . "\r\n")) {
                                Tools::logm('The user ' . $newUsername . ' has been emailed');
                                $this->messages->add('i', sprintf(_('The new user %1$s has been sent an email at %2$s. You may have to check spam folder.'), $newUsername, $email));
                                Tools::redirect('?');
                                
                            } else {
                                Tools::logm('A problem has been encountered while sending an email');
                                $this->messages->add('e', _('A problem has been encountered while sending an email'));
                            }
                        } else {
                            Tools::logm('The user has been created, but the server did not authorize sending emails');
                            $this->messages->add('i', _('The server did not authorize sending a confirmation email, but the user was created.'));
                        }
                } else {
                    Tools::logm('The user has been created, but no email was saved, so no confimation email was sent');
                    $this->messages->add('i', _('The user was created, but no email was sent because email was not filled in'));
                }
                Tools::logm('The new user ' . $newUsername . ' has been installed');
                if (\Session::isLogged()) {
                    $this->messages->add('s', sprintf(_('The new user %s has been installed. Do you want to <a href="?logout">logout ?</a>'), $newUsername));
                }
                Tools::redirect();
                }
                else {
                    Tools::logm('error during adding new user');
                    Tools::redirect();
                }
            }
            else {
                $this->messages->add('e', sprintf(_('Error : An user with the name %s already exists !'), $newUsername));
                Tools::logm('An user with the name ' . $newUsername . ' already exists !');
                Tools::redirect();
            }
        }
        else {
            Tools::logm('Password or username were empty');
        }
    }

    /**
     * Delete an existing user
     */
    public function deleteUser($password)
    {
        if ($this->store->listUsers() > 1) {
            if (Tools::encodeString($password . $this->user->getUsername()) == $this->store->getUserPassword($this->user->getId())) {
                $username = $this->user->getUsername();
                $this->store->deleteUserConfig($this->user->getId());
                Tools::logm('The configuration for user '. $username .' has been deleted !');
                $this->store->deleteTagsEntriesAndEntries($this->user->getId());
                Tools::logm('The entries for user '. $username .' has been deleted !');
                $this->store->deleteUser($this->user->getId());
                Tools::logm('User '. $username .' has been completely deleted !');
                Session::logout();
                Tools::logm('logout');
                Tools::redirect();
                $this->messages->add('s', sprintf(_('User %s has been successfully deleted !'), $username));
            }
            else {
                Tools::logm('Bad password !');
                $this->messages->add('e', _('Error : The password is wrong !'));
            }
        }
        else {
            Tools::logm('Only user !');
            $this->messages->add('e', _('Error : You are the only user, you cannot delete your account !'));
        }
    }

    public function getDefaultConfig()
    {
        return array(
            'pager' => PAGINATION,
            'language' => LANG,
            'theme' => DEFAULT_THEME
        );
    }

    /**
     * Call action (mark as fav, archive, delete, etc.)
     */
    public function action($action, Url $url, $id = 0, $import = FALSE, $autoclose = FALSE, $tags = null)
    {
        switch ($action)
        {
            case 'add':
                $content = Tools::getPageContent($url);
                $title = ($content['rss']['channel']['item']['title'] != '') ? $content['rss']['channel']['item']['title'] : _('Untitled');
                $body = $content['rss']['channel']['item']['description'];

                // clean content from prevent xss attack
                $purifier = $this->_getPurifier();
                $title = $purifier->purify($title);
                $body = $purifier->purify($body);

                //search for possible duplicate
                $duplicate = NULL;
                $clean_url = $url->getUrl();

                // Clean URL to remove parameters from feedburner and all this stuff. Taken from Shaarli.
                $i=strpos($clean_url,'&utm_source='); if ($i!==false) $clean_url=substr($clean_url,0,$i);
                $i=strpos($clean_url,'?utm_source='); if ($i!==false) $clean_url=substr($clean_url,0,$i);
                $i=strpos($clean_url,'#xtor=RSS-'); if ($i!==false) $clean_url=substr($clean_url,0,$i);

                $duplicate = $this->store->retrieveOneByURL($clean_url, $this->user->getId());

                $last_id = $this->store->add($clean_url, $title, $body, $this->user->getId());
                if ( $last_id ) {
                    Tools::logm('add link ' . $clean_url);
                    if (DOWNLOAD_PICTURES) {
                        $content = Picture::filterPicture($body, $clean_url, $last_id);
                        Tools::logm('updating content article');
                        $this->store->updateContent($last_id, $content, $this->user->getId());
                    }

                    if ($duplicate != NULL) {
                        // duplicate exists, so, older entry needs to be deleted (as new entry should go to the top of list), BUT favorite mark and tags should be preserved
                        Tools::logm('link ' . $clean_url . ' is a duplicate');
                        // 1) - preserve tags and favorite, then drop old entry
                        $this->store->reassignTags($duplicate['id'], $last_id);
                        if ($duplicate['is_fav']) {
                          $this->store->favoriteById($last_id, $this->user->getId());
                        }
                        if ($this->store->deleteById($duplicate['id'], $this->user->getId())) {
                          Tools::logm('previous link ' . $clean_url .' entry deleted');
                        }
                    }

                    // if there are tags, add them to the new article
                    if (isset($_GET['tags'])) {
                        $_POST['value'] = $_GET['tags'];
                        $_POST['entry_id'] = $last_id;
                        $this->action('add_tag', $url);
                    }

                    $this->messages->add('s', _('the link has been added successfully'));
                }
                else {
                    $this->messages->add('e', _('error during insertion : the link wasn\'t added'));
                    Tools::logm('error during insertion : the link wasn\'t added ' . $clean_url);
                }

		if (!$this->actionOnly) {
                if ($autoclose == TRUE) {
                    Tools::redirect('?view=home&closewin=true');
                } else {
                    Tools::redirect('?view=home');
                }
		}
                return $last_id;
                break;
            case 'delete':
                if (isset($_GET['search'])) {
                    //when we want to apply a delete to a search
                    $tags = array($_GET['search']);
                    $allentry_ids = $this->store->search($tags[0], $this->user->getId());
                    $entry_ids = array();
                    foreach ($allentry_ids as $eachentry) {
                        $entry_ids[] = $eachentry[0];
                    }
                } else { // delete a single article
                    $entry_ids = array($id);
                }
                foreach($entry_ids as $id) {
                    $msg = 'delete link #' . $id;

                    // deleting tags and tags_entries
                    $tags = $this->store->retrieveTagsByEntry($id);
                    foreach ($tags as $tag) {
                        $this->store->removeTagForEntry($id, $tag['id']);
                        $this->store->cleanUnusedTag($tag['id']);
                    }

                    // deleting pictures
                    if ($this->store->deleteById($id, $this->user->getId())) {
                        if (DOWNLOAD_PICTURES) {
                            Picture::removeDirectory(ABS_PATH . $id);
                        }
                        $this->messages->add('s', _('the link has been deleted successfully'));
                    }
                    else {
                        $this->messages->add('e', _('the link wasn\'t deleted'));
                        $msg = 'error : can\'t delete link #' . $id;
                    }
                    Tools::logm($msg);
                }
                Tools::redirect('?');
                break;
            case 'toggle_fav' :
                $this->store->favoriteById($id, $this->user->getId());
                Tools::logm('mark as favorite link #' . $id);
                if ( Tools::isAjaxRequest() ) {
                  echo 1;
                  exit;
                }
                else {
                  Tools::redirect();
                }
                break;
            case 'toggle_archive' :
                if (isset($_GET['tag_id'])) {
                    //when we want to archive a whole tag
                    $tag_id = $_GET['tag_id'];
                    $allentry_ids = $this->store->retrieveEntriesByTag($tag_id, $this->user->getId());
                    $entry_ids = array();
                    foreach ($allentry_ids as $eachentry) {
                        $entry_ids[] = $eachentry[0];
                    }
                } else { //archive a single article
                    $entry_ids = array($id);
                }
                foreach($entry_ids as $id) {
                    $this->store->archiveById($id, $this->user->getId());
                    Tools::logm('archive link #' . $id);
                }
                if ( Tools::isAjaxRequest() ) {
                  echo 1;
                  exit;
                } else {
                  Tools::redirect();
                }
                break;
            case 'archive_and_next' :
                $nextid = $this->store->getPreviousArticle($id, $this->user->getId());
                $this->store->archiveById($id, $this->user->getId());
                Tools::logm('archive link #' . $id);
                Tools::redirect('?view=view&id=' . $nextid);
                break;
            case 'archive_all' :
                $this->store->archiveAll($this->user->getId());
                Tools::logm('archive all links');
                Tools::redirect();
                break;
            case 'add_tag' :
                if (isset($_GET['search'])) {
                    //when we want to apply a tag to a search
                    $tags = array($_GET['search']);
                    $allentry_ids = $this->store->search($tags[0], $this->user->getId());
                    $entry_ids = array();
                    foreach ($allentry_ids as $eachentry) {
                        $entry_ids[] = $eachentry[0];
                    }
                } else { //add a tag to a single article
                    $tags = explode(',', $_POST['value']);
                    $entry_ids = array($_POST['entry_id']);
                }
                foreach($entry_ids as $entry_id) {
                    $entry = $this->store->retrieveOneById($entry_id, $this->user->getId());
                    if (!$entry) {
                        $this->messages->add('e', _('Article not found!'));
                        Tools::logm('error : article not found');
                        Tools::redirect();
                    }
                    //get all already set tags to preven duplicates
                    $already_set_tags = array();
                    $entry_tags = $this->store->retrieveTagsByEntry($entry_id);
                    foreach ($entry_tags as $tag) {
                      $already_set_tags[] = $tag['value'];
                    }
                    foreach($tags as $key => $tag_value) {
                        $value = trim($tag_value);
                        if ($value && !in_array($value, $already_set_tags)) {
                          $tag = $this->store->retrieveTagByValue($value);
                          if (is_null($tag)) {
                              # we create the tag
                              $tag = $this->store->createTag($value);
                              $sequence = '';
                              if (STORAGE == 'postgres') {
                                  $sequence = 'tags_id_seq';
                              }
                              $tag_id = $this->store->getLastId($sequence);
                          }
                          else {
                              $tag_id = $tag['id'];
                          }

                          # we assign the tag to the article
                          $this->store->setTagToEntry($tag_id, $entry_id);
                        }
                    }
                }
                $this->messages->add('s', _('The tag has been applied successfully'));
                Tools::logm('The tag has been applied successfully');
                Tools::redirect();
                break;
            case 'remove_tag' :
                $tag_id = $_GET['tag_id'];
                $entry = $this->store->retrieveOneById($id, $this->user->getId());
                if (!$entry) {
                    $this->messages->add('e', _('Article not found!'));
                    Tools::logm('error : article not found');
                    Tools::redirect();
                }
                $this->store->removeTagForEntry($id, $tag_id);
                Tools::logm('tag entry deleted');
                if ($this->store->cleanUnusedTag($tag_id)) {
                    Tools::logm('tag deleted');
                }
                $this->messages->add('s', _('The tag has been successfully deleted'));
                Tools::redirect();
                break;

            case 'reload_article' :
                Tools::logm('reload article');
                $id = $_GET['id'];
                $entry = $this->store->retrieveOneById($id, $this->user->getId());
                Tools::logm('reload url ' . $entry['url']);
                $url = new Url(base64_encode($entry['url']));
                $this->action('add', $url);
                break;
                
            /* For some unknown reason I can't get displayView() to work here (it redirects to home view afterwards). So here's a dirty fix which redirects directly to URL */
            case 'random':
                Tools::logm('get a random article');
                $view = $_GET['view'];
                if ($this->store->getRandomId($this->user->getId(),$view)) {
                    $id_array = $this->store->getRandomId($this->user->getId(),$view);
                    $id = $id_array[0];
                    Tools::redirect('?view=view&id=' . $id[0]);
                    Tools::logm('got the article with id ' . $id[0]);
                }
                break;
            default:
                break;
        }
    }

    function displayView($view, $id = 0)
    {
        $tpl_vars = array();

        switch ($view)
        {
            case 'about':
                break;
            case 'config':
                $dev_infos = $this->_getPocheVersion('dev');
                $dev = trim($dev_infos[0]);
                $check_time_dev = date('d-M-Y H:i', $dev_infos[1]);
                $prod_infos = $this->_getPocheVersion('prod');
                $prod = trim($prod_infos[0]);
                $check_time_prod = date('d-M-Y H:i', $prod_infos[1]);
                $compare_dev = version_compare(POCHE, $dev);
                $compare_prod = version_compare(POCHE, $prod);
                $themes = $this->tpl->getInstalledThemes();
                $languages = $this->language->getInstalledLanguages();
                $token = $this->user->getConfigValue('token');
                $http_auth = isset($_SERVER['REMOTE_USER']);
                $only_user = ($this->store->listUsers() > 1) ? false : true;
                $https = substr(Tools::getPocheUrl(), 0, 5) == 'https';
                $tpl_vars = array(
                    'themes' => $themes,
                    'languages' => $languages,
                    'dev' => $dev,
                    'prod' => $prod,
                    'check_time_dev' => $check_time_dev,
                    'check_time_prod' => $check_time_prod,
                    'compare_dev' => $compare_dev,
                    'compare_prod' => $compare_prod,
                    'token' => $token,
                    'user_id' => $this->user->getId(),
                    'http_auth' => $http_auth,
                    'only_user' => $only_user,
                    'https' => $https
                );
                Tools::logm('config view');
                break;
            case 'edit-tags':
                # tags
                $entry = $this->store->retrieveOneById($id, $this->user->getId());
                if (!$entry) {
                    $this->messages->add('e', _('Article not found!'));
                    Tools::logm('error : article not found');
                    Tools::redirect();
                }
                $tags = $this->store->retrieveTagsByEntry($id);
                $all_tags = $this->store->retrieveAllTags($this->user->getId());
                $maximus = 0;
                foreach ($all_tags as $eachtag) { // search for the most times a tag is present
                    if ($eachtag["entriescount"] > $maximus) $maximus = $eachtag["entriescount"];
                }
                foreach ($all_tags as $key => $eachtag) { // get the percentage of presence of each tag
                    $percent = floor(($eachtag["entriescount"] / $maximus) * 100);

                    if ($percent < 20): // assign a css class, depending on the number of entries count
                        $cssclass = 'smallesttag';
                    elseif ($percent >= 20 and $percent < 40):
                        $cssclass = 'smalltag';
                    elseif ($percent >= 40 and $percent < 60):
                        $cssclass = 'mediumtag';
                    elseif ($percent >= 60 and $percent < 80):
                        $cssclass = 'largetag';
                    else:
                        $cssclass = 'largesttag';
                    endif;
                    $all_tags[$key]['cssclass'] = $cssclass;
                }
                $tpl_vars = array(
                    'entry_id' => $id,
                    'tags' => $tags,
                    'alltags' => $all_tags,
                    'entry' => $entry,
                );
                break;
            case 'tags':
                $token = $this->user->getConfigValue('token');
                //if term is set - search tags for this term
                $term = Tools::checkVar('term');
                $tags = $this->store->retrieveAllTags($this->user->getId(), $term);
                if (Tools::isAjaxRequest()) {
                  $result = array();
                  foreach ($tags as $tag) {
                    $result[] = $tag['value'];
                  }
                  echo json_encode($result);
                  exit;
                }
                $tpl_vars = array(
                    'token' => $token,
                    'user_id' => $this->user->getId(),
                    'tags' => $tags,
                );
                break;
            case 'search':
                if (isset($_GET['search'])) {
                   $search = filter_var($_GET['search'], FILTER_SANITIZE_STRING);
                   $tpl_vars['entries'] = $this->store->search($search, $this->user->getId());
                   $count = count($tpl_vars['entries']);
                   $this->pagination->set_total($count);
                   $page_links = str_replace(array('previous', 'next'), array(_('previous'), _('next')),
                            $this->pagination->page_links('?view=' . $view . '?search=' . $search . '&sort=' . $_SESSION['sort'] . '&' ));
                   $tpl_vars['page_links'] = $page_links;
                   $tpl_vars['nb_results'] = $count;
                   $tpl_vars['searchterm'] = $search;
                }
                break;
            case 'view':
                $entry = $this->store->retrieveOneById($id, $this->user->getId());
                if ($entry != NULL) {
                    Tools::logm('view link #' . $id);
                    $content = $entry['content'];
                    if (function_exists('tidy_parse_string')) {
                        $tidy = tidy_parse_string($content, array('indent'=>true, 'show-body-only' => true), 'UTF8');
                        $tidy->cleanRepair();
                        $content = $tidy->value;
                    }

                    # flattr checking
                    $flattr = NULL;
                    if (FLATTR) {
                        $flattr = new FlattrItem();
                        $flattr->checkItem($entry['url'], $entry['id']);
                    }
                    
                    # previous and next
                    $previous = FALSE;
                    $previous_id = $this->store->getPreviousArticle($id, $this->user->getId());
                    $next = FALSE;
                    $next_id = $this->store->getNextArticle($id, $this->user->getId());

                    if ($this->store->retrieveOneById($previous_id, $this->user->getId())) {
                        $previous = TRUE;
                    }
                    if ($this->store->retrieveOneById($next_id, $this->user->getId())) {
                        $next = TRUE;
                    }
                    $navigate = array('previous' => $previous, 'previousid' => $previous_id, 'next' => $next, 'nextid' => $next_id);

                    # tags
                    $tags = $this->store->retrieveTagsByEntry($entry['id']);

                    $tpl_vars = array(
                        'entry' => $entry,
                        'content' => $content,
                        'flattr' => $flattr,
                        'tags' => $tags,
                        'navigate' => $navigate
                    );
                }
                else {
                    Tools::logm('error in view call : entry is null');
                }
                break;
            default: # home, favorites, archive and tag views
                $tpl_vars = array(
                    'entries' => '',
                    'page_links' => '',
                    'nb_results' => '',
                    'listmode' => (isset($_COOKIE['listmode']) ? true : false),
                    'view' => $view,
                );

                //if id is given - we retrieve entries by tag: id is tag id
                if ($id) {
                  $tpl_vars['tag'] = $this->store->retrieveTag($id, $this->user->getId());
                  $tpl_vars['id'] = intval($id);
                }

                $count = $this->store->getEntriesByViewCount($view, $this->user->getId(), $id);

                if ($count && $count > 0) {
                    $this->pagination->set_total($count);
                    $page_links = str_replace(array('previous', 'next'), array(_('previous'), _('next')),
                        $this->pagination->page_links('?view=' . $view . '&sort=' . $_SESSION['sort'] . (($id)?'&id='.$id:'') . '&' ));
                    $tpl_vars['entries'] = $this->store->getEntriesByView($view, $this->user->getId(), $this->pagination->get_limit(), $id);
                    $tpl_vars['page_links'] = $page_links;
                    $tpl_vars['nb_results'] = $count;
                }
                Tools::logm('display ' . $view . ' view');
                break;
        }

        return $tpl_vars;
    }

    /**
     * update the password of the current user.
     * if MODE_DEMO is TRUE, the password can't be updated.
     * @todo add the return value
     * @todo set the new password in function header like this updatePassword($newPassword)
     * @return boolean
     */
    public function updatePassword($password, $confirmPassword)
    {
        if (MODE_DEMO) {
            $this->messages->add('i', _('in demo mode, you can\'t update your password'));
            Tools::logm('in demo mode, you can\'t do this');
            Tools::redirect('?view=config');
        }
        else {
            if (isset($password) && isset($confirmPassword)) {
                if ($password == $confirmPassword && !empty($password)) {
                    $this->messages->add('s', _('your password has been updated'));
                    $this->store->updatePassword($this->user->getId(), Tools::encodeString($password . $this->user->getUsername()));
                    Session::logout();
                    Tools::logm('password updated');
                    Tools::redirect();
                }
                else {
                    $this->messages->add('e', _('the two fields have to be filled & the password must be the same in the two fields'));
                    Tools::redirect('?view=config');
                }
            }
        }
    }

    /**
     * Get credentials from differents sources
     * It redirects the user to the $referer link
     *
     * @return array
     */
    private function credentials()
    {
        if (!empty($_POST['login']) && !empty($_POST['password'])) {
            return array($_POST['login'], $_POST['password'], false);
        }
        if (isset($_SERVER['REMOTE_USER'])) {
            return array($_SERVER['REMOTE_USER'], 'http_auth', true);
        }

        return array(false, false, false);
    }

    /**
     * checks if login & password are correct and save the user in session.
     * it redirects the user to the $referer link
     * @param  string $referer the url to redirect after login
     * @todo add the return value
     * @return boolean
     */
    public function login($referer)
    {
        list($login,$password,$isauthenticated)=$this->credentials();
        if($login === false || $password === false) {
            $this->messages->add('e', _('login failed: you have to fill all fields'));
            Tools::logm('login failed');
            Tools::redirect();
        }
        if (!empty($login) && !empty($password)) {
            $user = $this->store->login($login, Tools::encodeString($password . $login), $isauthenticated);
            if ($user != array()) {
                # Save login into Session
                $longlastingsession = isset($_POST['longlastingsession']);
                $passwordTest = ($isauthenticated) ? $user['password'] : Tools::encodeString($password . $login);
                Session::login($user['username'], $user['password'], $login, $passwordTest, $longlastingsession, array('poche_user' => new User($user)));

                # reload l10n
                $language = $user['config']['language'];
                @putenv('LC_ALL=' . $language);
                setlocale(LC_ALL, $language);
                bindtextdomain($language, LOCALE);
                textdomain($language);
                bind_textdomain_codeset($language, 'UTF-8');

                $this->messages->add('s', _('welcome to your wallabag'));
                Tools::logm('login successful');
                Tools::redirect($referer);
            }
            $this->messages->add('e', _('login failed: bad login or password'));
            // log login failure in web server log to allow fail2ban usage
            error_log('user '.$login.' authentication failure');
            Tools::logm('login failed');
            Tools::redirect();
        }
    }

    /**
     * log out the poche user. It cleans the session.
     * @todo add the return value
     * @return boolean
     */
    public function logout()
    {
        $this->user = array();
        Session::logout();
        Tools::logm('logout');
        Tools::redirect();
    }

    /**
     * import datas into your wallabag
     * @return boolean
     */

    public function import() {

      if ( isset($_FILES['file']) && $_FILES['file']['tmp_name'] ) {
        Tools::logm('Import stated: parsing file');

        // assume, that file is in json format
        $str_data = file_get_contents($_FILES['file']['tmp_name']);
        $data = json_decode($str_data, true);

        if ( $data === null ) {
          //not json - assume html
          $html = new simple_html_dom();
          $html->load_file($_FILES['file']['tmp_name']);
          $data = array();
          $read = 0;

          if (Tools::get_doctype($html)->innertext == "<!DOCTYPE NETSCAPE-Bookmark-file-1>") {
            // Firefox-bookmarks HTML
            foreach (array('DL','ul') as $list) {
                foreach ($html->find($list) as $ul) {
                  foreach ($ul->find('DT') as $li) {
                    $tmpEntry = array();
                      $a = $li->find('A');
                      $tmpEntry['url'] = $a[0]->href;
                      $tmpEntry['tags'] = $a[0]->tags;
                      $tmpEntry['is_read'] = $read;
                      if ($tmpEntry['url']) {
                        $data[] = $tmpEntry;
                      }
                  }
                  # the second <ol/ul> is for read links
                  $read = ((sizeof($data) && $read)?0:1);
                }
            }
          } else {
            // regular HTML
            foreach (array('ol','ul') as $list) {
                foreach ($html->find($list) as $ul) {
                  foreach ($ul->find('li') as $li) {
                    $tmpEntry = array();
                      $a = $li->find('a');
                      $tmpEntry['url'] = $a[0]->href;
                      $tmpEntry['tags'] = $a[0]->tags;
                      $tmpEntry['is_read'] = $read;
                      if ($tmpEntry['url']) {
                        $data[] = $tmpEntry;
                      }
                  }
                  # the second <ol/ul> is for read links
                  $read = ((sizeof($data) && $read)?0:1);
                }
              }
        	}
        }

            // for readability structure

            foreach($data as $record) {
                if (is_array($record)) {
                    $data[] = $record;
                    foreach($record as $record2) {
                        if (is_array($record2)) {
                            $data[] = $record2;
                        }
                    }
                }
            }

            $urlsInserted = array(); //urls of articles inserted
            foreach($data as $record) {
                $url = trim(isset($record['article__url']) ? $record['article__url'] : (isset($record['url']) ? $record['url'] : ''));
                if (filter_var($url, FILTER_VALIDATE_URL) and !in_array($url, $urlsInserted)) {
                    $title = (isset($record['title']) ? $record['title'] : _('Untitled - Import - ') . '</a> <a href="./?import">' . _('click to finish import') . '</a><a>');
                    $body = (isset($record['content']) ? $record['content'] : '');
                    $isRead = (isset($record['is_read']) ? intval($record['is_read']) : (isset($record['archive']) ? intval($record['archive']) : 0));
                    $isFavorite = (isset($record['is_fav']) ? intval($record['is_fav']) : (isset($record['favorite']) ? intval($record['favorite']) : 0));

                    // insert new record

                    $id = $this->store->add($url, $title, $body, $this->user->getId() , $isFavorite, $isRead);
                    if ($id) {
                        $urlsInserted[] = $url; //add
                        if (isset($record['tags']) && trim($record['tags'])) {

                            $tags = explode(',', $record['tags']);														
							foreach($tags as $tag) {
								$entry_id = $id;
								$tag_id = $this->store->retrieveTagByValue($tag);
								if ($tag_id) {
									$this->store->setTagToEntry($tag_id['id'], $entry_id);									
								} else {
									$this->store->createTag($tag);
									$tag_id = $this->store->retrieveTagByValue($tag);
									$this->store->setTagToEntry($tag_id['id'], $entry_id);
								}
							}

                        }
                    }
                }
            }

            $i = sizeof($urlsInserted);
            if ($i > 0) {
                $this->messages->add('s', _('Articles inserted: ') . $i . _('. Please note, that some may be marked as "read".'));
            }

        Tools::logm('Import of articles finished: '.$i.' articles added (w/o content if not provided).');
      }
      else {
        $this->messages->add('e', _('Did you forget to select a file?'));
      }
        // file parsing finished here
        // now download article contents if any
        // check if we need to download any content

        $recordsDownloadRequired = $this->store->retrieveUnfetchedEntriesCount($this->user->getId());

        if ($recordsDownloadRequired == 0) {

            // nothing to download

            $this->messages->add('s', _('Import finished.'));
            Tools::logm('Import finished completely');
            Tools::redirect();
        }
        else {

            // if just inserted - don't download anything, download will start in next reload

            if (!isset($_FILES['file'])) {

                // download next batch

                Tools::logm('Fetching next batch of articles...');
                $items = $this->store->retrieveUnfetchedEntries($this->user->getId() , IMPORT_LIMIT);
                $purifier = $this->_getPurifier();
                foreach($items as $item) {
                    $url = new Url(base64_encode($item['url']));
                    if( $url->isCorrect() )
                    {
                        Tools::logm('Fetching article ' . $item['id']);
                        $content = Tools::getPageContent($url);
                        $title = (($content['rss']['channel']['item']['title'] != '') ? $content['rss']['channel']['item']['title'] : _('Untitled'));
                        $body = (($content['rss']['channel']['item']['description'] != '') ? $content['rss']['channel']['item']['description'] : _('Undefined'));

                        // clean content to prevent xss attack

                        $title = $purifier->purify($title);
                        $body = $purifier->purify($body);
                        $this->store->updateContentAndTitle($item['id'], $title, $body, $this->user->getId());
                        Tools::logm('Article ' . $item['id'] . ' updated.');
                    } else
                    {
                        Tools::logm('Unvalid URL (' . $item['url'] .')  to fetch for article ' . $item['id']);
                    }
                }
            }
        }

        return array(
            'includeImport' => true,
            'import' => array(
                'recordsDownloadRequired' => $recordsDownloadRequired,
                'recordsUnderDownload' => IMPORT_LIMIT,
                'delay' => IMPORT_DELAY * 1000
            )
        );
    }

    /**
     * export poche entries in json
     * @return json all poche entries
     */
    public function export()
    {
        $filename = "wallabag-export-".$this->user->getId()."-".date("Y-m-d").".json";
        header('Content-Disposition: attachment; filename='.$filename);

        $entries = $this->store->retrieveAllWithTags($this->user->getId());
        if ($entries) {
            echo $this->tpl->render('export.twig', array(
            'export' => Tools::renderJson($entries),
            ));
            Tools::logm('export view');
        } else {
            Tools::logm('error accessing database while exporting');
        }
    }

    /**
     * Checks online the latest version of poche and cache it
     * @param  string $which 'prod' or 'dev'
     * @return string        latest $which version
     */
    private function _getPocheVersion($which = 'prod') {
      $cache_file = CACHE . '/' . $which;
      $check_time = time();

      # checks if the cached version file exists
      if (file_exists($cache_file) && (filemtime($cache_file) > (time() - 86400 ))) {
         $version = file_get_contents($cache_file);
         $check_time = filemtime($cache_file);
      } else {
         $version = file_get_contents('http://static.wallabag.org/versions/' . $which);
         file_put_contents($cache_file, $version, LOCK_EX);
      }
      return array($version, $check_time);
    }

    /**
     * Update token for current user
     */
    public function updateToken()
    {
        $token = Tools::generateToken();
        $this->store->updateUserConfig($this->user->getId(), 'token', $token);
        $currentConfig = $_SESSION['poche_user']->config;
        $currentConfig['token'] = $token;
        $_SESSION['poche_user']->setConfig($currentConfig);
        Tools::redirect();
    }

    /**
     * Generate RSS feeds for current user
     *
     * @param $token
     * @param $user_id
     * @param $tag_id if $type is 'tag', the id of the tag to generate feed for
     * @param string $type the type of feed to generate
     * @param int $limit the maximum number of items (0 means all)
     */
    public function generateFeeds($token, $user_id, $tag_id, $type = 'home', $limit = 0)
    {
        $allowed_types = array('home', 'fav', 'archive', 'tag');
        $config = $this->store->getConfigUser($user_id);

        if ($config == null) {
            die(sprintf(_('User with this id (%d) does not exist.'), $user_id));
        }

        if (!in_array($type, $allowed_types) || !isset($config['token']) || $token != $config['token']) {
            die(_('Uh, there is a problem while generating feed. Wrong token used?'));
        }

        $feed = new FeedWriter(RSS2);
        $feed->setTitle('wallabag — ' . $type . ' feed');
        $feed->setLink(Tools::getPocheUrl());
        $feed->setChannelElement('pubDate', date(DATE_RSS , time()));
        $feed->setChannelElement('generator', 'wallabag');
        $feed->setDescription('wallabag ' . $type . ' elements');

        if ($type == 'tag') {
            $entries = $this->store->retrieveEntriesByTag($tag_id, $user_id);
        }
        else {
            $entries = $this->store->getEntriesByView($type, $user_id);
        }

        // if $limit is set to zero, use all entries
        if (0 == $limit) {
            $limit = count($entries);
        }
        if ($entries && count($entries) > 0) {
            for ($i = 0; $i < min(count($entries), $limit); $i++) {
                $entry = $entries[$i];
                $newItem = $feed->createNewItem();
                $newItem->setTitle($entry['title']);
                $newItem->setSource(Tools::getPocheUrl() . '?view=view&amp;id=' . $entry['id']);
                $newItem->setLink($entry['url']);
                $newItem->setDate(time());
                $newItem->setDescription($entry['content']);
                $feed->addItem($newItem);
            }
        }
        else 
            {
                Tools::logm("database error while generating feeds");
            }
        $feed->genarateFeed();
        exit;
    }



    /**
     * Returns new purifier object with actual config
     */
    private function _getPurifier()
    {
        $config = HTMLPurifier_Config::createDefault();
        $config->set('Cache.SerializerPath', CACHE);
        $config->set('HTML.SafeIframe', true);

        //allow YouTube, Vimeo and dailymotion videos
        $config->set('URI.SafeIframeRegexp', '%^(https?:)?//(www\.youtube(?:-nocookie)?\.com/embed/|player\.vimeo\.com/video/|www\.dailymotion\.com/embed/video/)%');

        return new HTMLPurifier($config);
    }


}
