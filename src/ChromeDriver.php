<?php
namespace DMore\ChromeDriver;

use Behat\Mink\Driver\CoreDriver;
use Behat\Mink\Exception\DriverException;
use Behat\Mink\Exception\ElementNotFoundException;
use WebSocket\ConnectionException;

class ChromeDriver extends CoreDriver
{
    /** @var ChromeBrowser */
    private $browser;
    /** @var ChromePage */
    private $page;
    private $is_started = false;
    /** @var string */
    private $api_url;
    /** @var string */
    private $ws_url;
    /** @var string */
    private $current_window;
    /** @var string */
    private $main_window;
    /** @var HttpClient */
    private $http_client;
    /** @var string[] */
    private $request_headers = [];
    /** @var string */
    private $base_url;
    /**
     * @var string The document node to run xpath queries on.
     * Can either be 'document' or valid javascript for an iframe's javascript
     */
    private $document = 'document';

    /**
     * ChromeDriver constructor.
     * @param string $api_url
     * @param HttpClient $http_client
     * @param $base_url
     */
    public function __construct($api_url = 'http://localhost:9222', HttpClient $http_client = null, $base_url)
    {
        if ($http_client == null) {
            $http_client = new HttpClient();
        }
        $this->http_client = $http_client;
        $this->api_url = $api_url;
        $this->ws_url = str_replace('http', 'ws', $api_url);
        $this->base_url = $base_url;
        $this->browser = new ChromeBrowser($this->ws_url . '/devtools/browser');
        $this->browser->setHttpClient($http_client);
        $this->browser->setHttpUri($api_url);
    }

    public function start()
    {
        $this->browser->connect();
        $this->main_window = $this->browser->start();
        $this->connectToWindow($this->main_window);
        $this->is_started = true;
    }

    /**
     * Checks whether driver is started.
     *
     * @return Boolean
     */
    public function isStarted()
    {
        return $this->is_started;
    }

    /**
     * Stops driver.
     *
     * Once stopped, the driver should be started again before using it again.
     *
     * Calling any action on a stopped driver is an undefined behavior.
     * The only supported method call after stopping a driver is starting it again.
     *
     * Calling stop on a stopped driver is an undefined behavior. Driver
     * implementations are free to handle it silently or to fail with an
     * exception.
     *
     * @throws DriverException When the driver cannot be closed
     */
    public function stop()
    {
        try {
            @$this->reset();
            $this->browser->close();
            foreach ($this->page->getTabs() as $tab) {
                $this->http_client->get($this->api_url . '/json/close/' . $tab['windowId']);
            }
        } catch (ConnectionException $exception) {
        } catch (DriverException $exception) {
        }

        $this->is_started = false;
    }

    /**
     * Resets driver state.
     *
     * This should reset cookies, request headers and basic authentication.
     * When possible, the history should be reset as well, but this is not enforced
     * as some implementations may not be able to reset it without restarting the
     * driver entirely. Consumers requiring a clean history should restart the driver
     * to enforce it.
     *
     * Once reset, the driver should be ready to visit a page.
     * Calling any action before visiting a page is an undefined behavior.
     * The only supported method calls on a fresh driver are
     * - visit()
     * - setRequestHeader()
     * - setBasicAuth()
     * - reset()
     * - stop()
     *
     * Calling reset on a stopped driver is an undefined behavior.
     */
    public function reset()
    {
        $this->document = 'document';
        $this->deleteAllCookies();
        $this->connectToWindow($this->main_window);
        $this->page->reset();
        $this->request_headers = [];
        $this->sendRequestHeaders();
    }

    /**
     * Visit specified URL.
     *
     * @param string $url url of the page
     *
     * @throws DriverException                  When the operation cannot be done
     */
    public function visit($url)
    {
        $this->page->visit($url);
        $this->page->waitForLoad();
        $this->waitForDom();
    }

    /**
     * Returns current URL address.
     *
     * @return string
     *
     * @throws DriverException                  When the operation cannot be done
     */
    public function getCurrentUrl()
    {
        $this->waitForDom();
        return $this->evaluateScript('window.location.href');
    }

    /**
     * Reloads current page.
     *
     * @throws DriverException                  When the operation cannot be done
     */
    public function reload()
    {
        $this->page->reload();
        $this->page->waitForLoad();
    }

    /**
     * Moves browser forward 1 page.
     *
     * @throws DriverException                  When the operation cannot be done
     */
    public function forward()
    {
        $this->runScript('window.history.forward()');
        $this->waitForDom();
        $this->page->waitForLoad();
    }

    /**
     * Moves browser backward 1 page.
     *
     * @throws DriverException                  When the operation cannot be done
     */
    public function back()
    {
        $this->runScript('window.history.back()');
        $this->waitForDom();
        $this->page->waitForLoad();
    }

    /**
     * {@inheritdoc}
     */
    public function setBasicAuth($user, $password)
    {
        if ($user === false) {
            $this->unsetRequestHeader('Authorization');
        } else {
            $this->setRequestHeader('Authorization', 'Basic ' . base64_encode($user . ':' . $password));
        }
    }

    /**
     * {@inheritdoc}
     */
    public function switchToWindow($name = null)
    {
        if (null === $name) {
            $this->connectToWindow($this->main_window);
        } else {
            foreach ($this->page->getTabs() as $tab) {
                if ($tab['targetId'] == $name || $tab['title'] == $name) {
                    $this->connectToWindow($tab['targetId']);
                    return;
                }
            }
            try {
                $this->runScript("window.latest_popup = window.open('', '{$name}');");
                $condition = "window.latest_popup.location.href != 'about:blank';";
                $this->wait(2000, $condition);
                $script = "[window.latest_popup.document.title, window.latest_popup.location.href]";
                list($title, $href) = $this->evaluateScript($script);

                foreach ($this->getWindowNames() as $id) {
                    $info = $this->page->send('Target.getTargetInfo', ['targetId' => $id])['targetInfo'];
                    if ($info['type'] === 'page' && $info['url'] == $href && $info['title'] == $title) {
                        $this->switchToWindow($id);
                        return;
                    }
                }
            } catch (\Exception $e) {
            }

            throw new DriverException("Couldn't find window {$name}");
        }
    }

    /**
     * {@inheritdoc}
     */
    public function switchToIFrame($name = null)
    {
        if ($name == null) {
            $this->document = 'document';
        } else {
            $xpath = "//IFRAME[@id='{$name}' or @name='{$name}']";
            $script = <<<JS
        window.active_iframe = document.evaluate("{$xpath}", {$this->document}.body).iterateNext();
        window.active_iframe != null;
JS;

            if (!$this->evaluateScript($script)) {
                throw new DriverException("No frame with id or name '{$name}' was found.");
            }
            $this->document = "window.active_iframe.contentWindow.document";
        }
    }

    /**
     * {@inheritdoc}
     */
    public function setRequestHeader($name, $value)
    {
        $this->request_headers[$name] = $value;
        $this->sendRequestHeaders();
    }

    /**
     * @param $name
     */
    public function unsetRequestHeader($name)
    {
        if (array_key_exists($name, $this->request_headers)) {
            unset($this->request_headers);
            $this->sendRequestHeaders();
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getResponseHeaders()
    {
        return $this->page->getResponse()['headers'];
    }

    /**
     * Sets cookie.
     *
     * @param string $name
     * @param string $value
     *
     * @throws DriverException                  When the operation cannot be done
     */
    public function setCookie($name, $value = null)
    {
        if ($value === null) {
            foreach ($this->page->send('Network.getAllCookies')['cookies'] as $cookie) {
                if ($cookie['name'] == $name) {
                    $parameters = ['cookieName' => $name, 'url' => 'http://' . $cookie['domain'] . $cookie['path']];
                    $this->page->send('Network.deleteCookie', $parameters);
                }
            }
        } else {
            $url = $this->base_url . '/';
            $value = urlencode($value);
            $this->page->send('Network.setCookie', ['url' => $url, 'name' => $name, 'value' => $value]);
        }
    }

    /**
     * Returns cookie by name.
     *
     * @param string $name
     *
     * @return string|null
     *
     * @throws DriverException                  When the operation cannot be done
     */
    public function getCookie($name)
    {
        $result = $this->page->send('Network.getCookies');

        foreach ($result['cookies'] as $cookie) {
            if ($cookie['name'] == $name) {
                return urldecode($cookie['value']);
            }
        }
        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function getStatusCode()
    {
        return $this->page->getResponse()['status'];
    }

    /**
     * Returns last response content.
     *
     * @return string
     *
     * @throws DriverException                  When the operation cannot be done
     */
    public function getContent()
    {
        return $this->getHtml('//html');
    }

    /**
     * Capture a screenshot of the current window.
     *
     * @return string screenshot of MIME type image/* depending
     *                on driver (e.g., image/png, image/jpeg)
     *
     * @throws DriverException                  When the operation cannot be done
     */
    public function getScreenshot()
    {
        $screenshot = $this->page->send('Page.captureScreenshot');
        return base64_decode($screenshot['data']);
    }

    /**
     * {@inheritdoc}
     */
    public function getWindowNames()
    {
        $names = [];
        foreach ($this->page->getTabs() as $tab) {
            $names[] = $tab['targetId'];
        }
        return $names;
    }

    /**
     * Return the name of the currently active window.
     *
     * @return string the name of the current window
     *
     * @throws DriverException                  When the operation cannot be done
     */
    public function getWindowName()
    {
        return $this->current_window;
    }

    /**
     * {@inheritdoc}
     */
    public function getCurrentWindow()
    {
        return $this->current_window;
    }

    /**
     * Finds elements with specified XPath query.
     *
     * @param string $xpath
     * @return \Behat\Mink\Element\NodeElement[]
     * @throws ElementNotFoundException
     */
    protected function findElementXpaths($xpath)
    {
        $this->waitForDom();
        $expression = $this->getXpathExpression($xpath);
        $expression .= <<<JS
    function getPathTo(element) {
        if (typeof element.id == 'string' && element.id != '' && document.getElementById(element.id) === element) {
            return '//' + element.tagName + '[@id="'+element.id+'"]';
        }
        if (element === {$this->document}.body ||
            element === {$this->document}.head ||
            element === {$this->document}.documentElement
        ) {
            return '//' + element.tagName;
        }

        var ix = 0;
        var siblings = element.parentNode.childNodes;
        for (var i = 0; i < siblings.length; i++) {
            var sibling = siblings[i];
            if (sibling === element)
                return getPathTo(element.parentNode) + '/' + element.tagName + '[' + (ix + 1) + ']';
            if (sibling.nodeType===1 && sibling.tagName===element.tagName)
                ix++;
        }
    }
    var result = [];
    while (element = xpath_result.iterateNext()) {
        result.push(getPathTo(element));
    };
    result
JS;

        $value = $this->evaluateScript($expression);
        return $value;
    }

    /**
     * Returns element's tag name by it's XPath query.
     *
     * @param string $xpath
     * @return string
     * @throws ElementNotFoundException
     */
    public function getTagName($xpath)
    {
        return $this->getElementProperty($xpath, 'tagName');
    }

    /**
     * Returns element's text by it's XPath query.
     *
     * @param string $xpath
     * @return string
     * @throws ElementNotFoundException
     */
    public function getText($xpath)
    {
        $text = $this->getElementProperty($xpath, 'innerText');
        $text = trim(preg_replace('/\s+/', ' ', $text), ' ');
        return $text;
    }

    /**
     * {@inheritdoc}
     */
    public function getHtml($xpath)
    {
        return $this->getElementProperty($xpath, 'innerHTML');
    }

    /**
     * {@inheritdoc}
     */
    public function getOuterHtml($xpath)
    {
        return $this->getElementProperty($xpath, 'outerHTML');
    }

    /**
     * {@inheritdoc}
     */
    public function getAttribute($xpath, $name)
    {
        $name = addslashes($name);
        return $this->getElementProperty($xpath, "getAttribute('{$name}');");
    }

    /**
     * {@inheritdoc}
     */
    public function getValue($xpath)
    {
        $expression = $this->getXpathExpression($xpath);
        $expression .= <<<JS
        element = xpath_result.iterateNext();
    var value = null

    if (element.tagName == 'INPUT' && element.type == 'checkbox') {
        value = element.checked ? element.value : null;
    } else if (element.tagName == 'INPUT' && element.type == 'radio') {
        var name = element.getAttribute('name');
        if (name) {
            var fields = window.document.getElementsByName(name),
                i, l = fields.length;
            for (i = 0; i < l; i++) {
                var field = fields.item(i);
                if (field.form === element.form && field.checked) {
                    value = field.value;
                    break;
                }
            }
        }
    } else if (element.tagName == 'SELECT' && element.multiple) {
        value = []
        for (var i = 0; i < element.options.length; i++) {
            if (element.options[i].selected) {
                value.push(element.options[i].value);
            }
        }
    } else {
        value = element.value;
    }
    value
JS;

        return $this->evaluateScript($expression);
    }

    /**
     * {@inheritdoc}
     */
    public function setValue($xpath, $value)
    {
        $is_text_field = "(element.tagName == 'INPUT' && element.type == 'text') || element.tagName == 'TEXTAREA'";
        if (!$this->runScriptOnXpathElement($xpath, $is_text_field)) {
            $this->setNonTextTypeValue($xpath, $value);
        } else {
            $current_value = $this->getValue($xpath);
            $this->runScriptOnXpathElement($xpath, 'element.focus()');
            for ($i = 0; $i < strlen($current_value); $i++) {
                $this->page->send('Input.dispatchKeyEvent',
                    ['type' => 'rawKeyDown', 'nativeVirtualKeyCode' => 8, 'windowsVirtualKeyCode' => 8]);
                $this->page->send('Input.dispatchKeyEvent', ['type' => 'keyUp']);
                $this->page->send('Input.dispatchKeyEvent',
                    ['type' => 'rawKeyDown', 'nativeVirtualKeyCode' => 46, 'windowsVirtualKeyCode' => 46]);
                $this->page->send('Input.dispatchKeyEvent', ['type' => 'keyUp']);
            }
            for ($i = 0; $i < strlen($value); $i++) {
                $this->page->send('Input.dispatchKeyEvent', ['type' => 'keyDown', 'text' => substr($value, $i, 1)]);
                $this->page->send('Input.dispatchKeyEvent', ['type' => 'keyUp']);
            }
            $this->runScriptOnXpathElement($xpath, 'element.blur();');
        }
    }

    /**
     * @param $xpath
     * @param $value
     * @throws ElementNotFoundException
     * @throws \Exception
     */
    private function setNonTextTypeValue($xpath, $value)
    {
        $json_value = ctype_digit($value) ? $value : json_encode($value);
        $expression = <<<JS
    var expected_value = $json_value;
    var result = 0;
    var trigger_change = true;
    element.scrollIntoViewIfNeeded();
    element.focus();
    if (element.tagName == 'INPUT' && element.type == 'radio') {
        var name = element.name
        var fields = window.document.getElementsByName(name),
            i, l = fields.length;
        for (i = 0; i < l; i++) {
            var field = fields.item(i);
            if (field.form === element.form) {
                if (field.value === expected_value) {
                    field.checked = true;
                } else {
                    field.checked = false;
                }
            }
        }
    } else if (element.tagName == 'INPUT' && element.type == 'checkbox') {
        if (element.checked != expected_value) {
            element.click();
        }
        trigger_change = false;
    } else if (element.tagName == 'SELECT') {
        if (element.multiple && typeof expected_value != 'object') {
            expected_value = [expected_value]
        }
        for (var i = 0; i < element.options.length; i++) {
            if ((element.multiple && expected_value.includes(element.options[i].value)) || element.options[i].value == expected_value) {
                element.options[i].selected = true;
            } else {
                element.options[i].selected = false;
            }
        }
    } else if (element.tagName == 'INPUT' && element.type == 'file') {
    } else {
        element.value = expected_value
    }
    if (trigger_change) {
        var change = document.createEvent("Events");
        change.initEvent("change", true, true);
        element.dispatchEvent(change)
    }
    element.blur();
    null
JS;

        $result = $this->runScriptOnXpathElement($xpath, $expression);
    }

    /**
     * {@inheritdoc}
     */
    public function check($xpath)
    {
        $this->expectCheckbox($xpath);
        $this->setValue($xpath, true);
    }

    /**
     * {@inheritdoc}
     */
    public function uncheck($xpath)
    {
        $this->expectCheckbox($xpath);
        $this->setValue($xpath, false);
    }

    /**
     * {@inheritdoc}
     */
    public function isChecked($xpath)
    {
        return $this->getElementProperty($xpath, 'checked');
    }

    /**
     * {@inheritdoc}
     */
    public function selectOption($xpath, $value, $multiple = false)
    {
        $this->expectSelectOrRadio($xpath);
        if ($multiple) {
            $value = array_merge((array)$value, $this->getValue($xpath));
        }
        return $this->setValue($xpath, $value);
    }

    /**
     * {@inheritdoc}
     */
    public function click($xpath)
    {
        $this->mouseOver($xpath);

        list($left, $top) = $this->getCoordinatesForXpath($xpath);
        $this->page->send('Input.dispatchMouseEvent', ['type' => 'mouseMoved', 'x' => $left, 'y' => $top]);

        $parameters = [
            'type' => 'mousePressed',
            'x' => $left + 5,
            'y' => $top + 5,
            'button' => 'left',
            'timestamp' => time(),
            'clickCount' => 1,
        ];
        $this->page->send('Input.dispatchMouseEvent', $parameters);
        $parameters = [
            'type' => 'mouseReleased',
            'x' => $left + 5,
            'y' => $top + 5,
            'button' => 'left',
            'timestamp' => time(),
            'clickCount' => 1,
        ];
        $this->page->send('Input.dispatchMouseEvent', $parameters);
        usleep(5000);
        $this->waitForDom();
    }

    /**
     * {@inheritdoc}
     */
    public function attachFile($xpath, $path)
    {
        $script = <<<JS
    if (element == undefined || element.tagName != 'INPUT' || element.type != 'file') {
        throw new Error("Element not found");
    }
    element.name
JS;

        $name = $this->runScriptOnXpathElement($xpath, $script, 'file input');

        $node_id = null;
        foreach ($this->page->send('DOM.getFlattenedDocument')['nodes'] as $element) {
            if (!empty($element['attributes'])) {
                $num_attributes = count($element['attributes']);
                for ($key = 0; $key < $num_attributes; $key += 2) {
                    if ($element['attributes'][$key] == 'name' && $element['attributes'][$key + 1] == $name) {
                        $this->page->send('DOM.setFileInputFiles', ['nodeId' => $element['nodeId'], 'files' => [$path]]);
                        return;
                    }
                }
            }
        }

        throw new ElementNotFoundException($this, 'file', 'xpath', $xpath);
    }

    /**
     * {@inheritdoc}
     */
    public function doubleClick($xpath)
    {
        $this->click($xpath);
        $this->triggerMouseEvent($xpath, 'dblclick');
    }

    /**
     * {@inheritdoc}
     */
    public function rightClick($xpath)
    {
        $this->mouseOver($xpath);
        $this->triggerEvent($xpath, 'contextmenu');
    }

    /**
     * {@inheritdoc}
     */
    public function isVisible($xpath)
    {
        return $this->runScriptOnXpathElement($xpath, 'element.offsetWidth > 0 && element.offsetHeight > 0;');
    }

    /**
     * {@inheritdoc}
     */
    public function isSelected($xpath)
    {
        return $this->runScriptOnXpathElement($xpath, '!!element.selected', 'select');
    }

    /**
     * {@inheritdoc}
     */
    public function mouseOver($xpath)
    {
        $this->runScriptOnXpathElement($xpath, 'element.scrollIntoViewIfNeeded()');
        list($left, $top) = $this->getCoordinatesForXpath($xpath);
        $this->page->send('Input.dispatchMouseEvent', ['type' => 'mouseMoved', 'x' => $left, 'y' => $top]);
    }

    /**
     * {@inheritdoc}
     */
    public function focus($xpath)
    {
        $this->triggerEvent($xpath, 'focus');
    }

    /**
     * {@inheritdoc}
     */
    public function blur($xpath)
    {
        $this->triggerEvent($xpath, 'blur');
    }

    /**
     * {@inheritdoc}
     */
    public function keyPress($xpath, $char, $modifier = null)
    {
        $this->triggerKeyboardEvent($xpath, $char, $modifier, 'keypress');
    }

    /**
     * {@inheritdoc}
     */
    public function keyDown($xpath, $char, $modifier = null)
    {
        $this->triggerKeyboardEvent($xpath, $char, $modifier, 'keydown');
    }

    /**
     * {@inheritdoc}
     */
    public function keyUp($xpath, $char, $modifier = null)
    {
        $this->triggerKeyboardEvent($xpath, $char, $modifier, 'keyup');
    }

    /**
     * {@inheritdoc}
     */
    public function dragTo($sourceXpath, $destinationXpath)
    {
        list($left, $top) = $this->getCoordinatesForXpath($sourceXpath);
        $this->page->send('Input.dispatchMouseEvent', ['type' => 'mouseMoved', 'x' => $left, 'y' => $top]);
        $parameters = ['type' => 'mousePressed', 'x' => $left, 'y' => $top, 'button' => 'left'];
        $this->page->send('Input.dispatchMouseEvent', $parameters);

        list($left, $top) = $this->getCoordinatesForXpath($destinationXpath);
        $this->page->send('Input.dispatchMouseEvent', ['type' => 'mouseMoved', 'x' => $left, 'y' => $top]);
        $parameters = ['type' => 'mouseReleased', 'x' => $left, 'y' => $top, 'button' => 'left'];
        $this->page->send('Input.dispatchMouseEvent', $parameters);
    }

    /**
     * {@inheritdoc}
     */
    public function executeScript($script)
    {
        $this->evaluateScript($script);
    }

    /**
     * {@inheritdoc}
     */
    public function evaluateScript($script)
    {
        if (substr($script, 0, 8) === 'function') {
            $script = '(' . $script . ')';
            if (substr($script, -2) == ';)') {
                $script = substr($script, 0, -2) . ')';
            }
        }

        $result = $this->runScript($script)['result'];

        if (array_key_exists('subtype', $result) && $result['subtype'] === 'error') {
            if ($result['className'] === 'SyntaxError' && strpos($result['description'], 'Illegal return') !== false) {
                return $this->evaluateScript('(function() {' . $script . '}());');
            }
            throw new \Exception($result['description']);
        }

        if ($result['type'] == 'object' && array_key_exists('subtype', $result)) {
            if ($result['subtype'] == 'null') {
                return null;
            } elseif ($result['subtype'] == 'array' && $result['className'] == 'Array' && $result['objectId']) {
                return $this->fetchObjectProperties($result);
            } else {
                return [];
            }
        } elseif ($result['type'] == 'object' && $result['className'] == 'Object') {
            return $this->fetchObjectProperties($result);
        } elseif ($result['type'] == 'undefined') {
            return null;
        }

        if (!array_key_exists('value', $result)) {
            return null;
        }

        return $result['value'];
    }

    /**
     * {@inheritdoc}
     */
    public function wait($timeout, $condition)
    {
        $max_iterations = ceil($timeout / 10);
        $iterations = 0;

        do {
            $result = $this->evaluateScript($condition);
            if ($result || $iterations++ == $max_iterations) {
                break;
            }
            usleep(10000);
        } while (true);
        return (bool)$result;
    }

    /**
     * {@inheritdoc}
     */
    public function resizeWindow($width, $height, $name = null)
    {
        $this->executeScript("window.innerWidth = $width;window.innerHeight = $height;");
    }

    /**
     * {@inheritdoc}
     */
    public function maximizeWindow($name = null)
    {
        $this->executeScript("window.innerWidth = screen.width;window.innerHeight = screen.height;");
    }

    /**
     * {@inheritdoc}
     */
    public function submitForm($xpath)
    {
        $this->runScriptOnXpathElement($xpath, 'element.submit()', 'form');
    }

    public function acceptAlert($text = '')
    {
        $this->page->send('Page.handleJavaScriptDialog', ['accept' => true, 'promptText' => $text]);
    }

    public function dismissAlert()
    {
        $this->page->send('Page.handleJavaScriptDialog', ['accept' => false]);
    }

    protected function deleteAllCookies()
    {
        $this->page->send('Network.clearBrowserCookies');
    }

    /**
     * @param $xpath
     * @return string
     */
    protected function getXpathExpression($xpath)
    {
        $xpath = addslashes($xpath);
        $xpath = str_replace("\n", '\\n', $xpath);
        return "var xpath_result = document.evaluate(\"{$xpath}\", {$this->document}, null, XPathResult.ORDERED_NODE_ITERATOR_TYPE);";
    }

    protected function getElementProperty($xpath, $property)
    {
        return $this->runScriptOnXpathElement($xpath, 'element.' . $property);
    }

    /**
     * @param $xpath
     * @throws ElementNotFoundException
     */
    protected function expectSelectOrRadio($xpath)
    {
        $script = <<<JS
    element.tagName == 'SELECT' || (element.tagName == 'INPUT' && element.type == 'radio')
JS;
        if (!$this->runScriptOnXpathElement($xpath, $script)) {
            throw new ElementNotFoundException($this, 'select or radio', 'xpath', $xpath);
        }
    }

    /**
     * @param $xpath
     * @throws ElementNotFoundException
     */
    protected function expectCheckbox($xpath)
    {
        $script = <<<JS
    element.tagName == 'INPUT' && element.type == 'checkbox'
JS;
        if (!$this->runScriptOnXpathElement($xpath, $script)) {
            throw new ElementNotFoundException($this, 'checkbox', 'xpath', $xpath);
        }
    }

    /**
     * @param $xpath
     * @param $event
     * @throws ElementNotFoundException
     */
    protected function triggerMouseEvent($xpath, $event)
    {
        $script = <<<JS
    if (element) {
        element.dispatchEvent(new MouseEvent('$event', { bubbles: true }));
    }
    element != null
JS;

        $this->runScriptOnXpathElement($xpath, $script);
    }

    /**
     * @param $xpath
     * @param $event
     * @throws ElementNotFoundException
     */
    protected function triggerEvent($xpath, $event)
    {
        $script = <<<JS
    if (element) {
        element.dispatchEvent(new Event('$event'));
    }
    element != null
JS;

        $this->runScriptOnXpathElement($xpath, $script);
    }

    /**
     * @param $xpath
     * @param $char
     * @param $modifier
     * @param $event
     * @throws ElementNotFoundException
     */
    protected function triggerKeyboardEvent($xpath, $char, $modifier, $event)
    {
        if (is_string($char)) {
            $char = ord($char);
        }
        $options = [
            'ctrlKey' => $modifier == 'ctrl' ? 'true' : 'false',
            'altKey' => $modifier == 'alt' ? 'true' : 'false',
            'shiftKey' => $modifier == 'shift' ? 'true' : 'false',
            'metaKey' => $modifier == 'meta' ? 'true' : 'false',
        ];

        $script = <<<JS
    if (element) {
        element.focus();
        var event = document.createEvent("Events");
        event.initEvent("$event", true, true);
        event.key = $char;
        event.keyCode = $char;
        event.which = $char;
        event.ctrlKey = {$options['ctrlKey']};
        event.shiftKey = {$options['shiftKey']};
        event.altKey = {$options['altKey']};
        event.metaKey = {$options['metaKey']};

        element.dispatchEvent(event);
    }
    element != null;
JS;

        $this->runScriptOnXpathElement($xpath, $script);
    }

    /**
     * @param $xpath
     * @param $script
     * @param null $type
     * @return array
     * @throws ElementNotFoundException
     * @throws \Exception
     */
    protected function runScriptOnXpathElement($xpath, $script, $type = null)
    {
        $expression = $this->getXpathExpression($xpath);
        $expression .= <<<JS
    var element = xpath_result.iterateNext();
    if (null == element) {
        throw new Error("Element not found");
    }
JS;
        $expression .= $script;
        try {
            $result = $this->evaluateScript($expression);
        } catch (\Exception $exception) {
            if (strpos($exception->getMessage(), 'Element not found') !== false) {
                throw new ElementNotFoundException($this, $type, 'xpath', $xpath);
            }
            throw $exception;
        }
        return $result;
    }

    /**
     * @param $xpath
     * @return array
     */
    protected function getCoordinatesForXpath($xpath)
    {
        $expression = $this->getXpathExpression($xpath);
        $expression .= <<<JS
    var element = xpath_result.iterateNext();
    rect = element.getBoundingClientRect();
    [rect.left, rect.top]
JS;

        list($left, $top) = $this->evaluateScript($expression);
        $left = round($left + 1);
        $top = round($top + 1);
        return array($left, $top);
    }

    /**
     * @param $script
     * @return null
     */
    protected function runScript($script)
    {
        $this->page->waitForLoad();
        return $this->page->send('Runtime.evaluate', ['expression' => $script]);
    }

    /**
     * @param $result
     * @return array
     */
    protected function fetchObjectProperties($result)
    {
        $parameters = ['objectId' => $result['objectId'], 'ownProperties' => true];
        $properties = $this->page->send('Runtime.getProperties', $parameters)['result'];
        $return = [];
        foreach ($properties as $property) {
            if ($property['name'] !== '__proto__' && $property['name'] !== 'length') {
                if (!empty($property['value']['type']) && $property['value']['type'] == 'object' &&
                    !empty($property['value']['className']) &&
                    in_array($property['value']['className'], ['Array', 'Object'])
                ) {
                    $return[$property['name']] = $this->fetchObjectProperties($property['value']);
                } else {
                    $return[$property['name']] = $property['value']['value'];
                }
            }
        }
        return $return;
    }

    /**
     * @param $window_id
     */
    protected function connectToWindow($window_id)
    {
        if ($window_id === $this->current_window) {
            return;
        }

        $this->page = new ChromePage($this->ws_url . '/devtools/page/' . $window_id);
        $this->page->connect();
        $this->current_window = $window_id;
        $this->document = 'document';
    }

    protected function sendRequestHeaders()
    {
        $this->page->send('Network.setExtraHTTPHeaders', ['headers' => $this->request_headers ?: new \stdClass()]);
    }

    protected function waitForDom()
    {
        if (!$this->page->hasJavascriptDialog()) {
            $this->wait(3000, 'document.readyState == "complete"');
        }
    }
}
