<?php
namespace SLiMS\Ui\Components;

use Closure;
use SLiMS\Ui\Utils;
use SLiMS\Plugins;

abstract class Base
{
    use Utils;

    /**
     * Html tag element
     * @var string
     */
    protected string $tag = '';

    /**
     * Html element list which is no need
     * to have end tag
     */
    protected array $voidElements = [
        'area','base','br','col',
        'embed','hr','img','input',
        'link','meta','param','source',
        'track','wbr'
    ];

    /**
     * Slot is content between 
     * <some_tag> and </some_tag>
     */
    protected array $slots = [];

    /**
     * Html element attributes 
     * such as class, id etc
     */
    protected array $attributes = [];

    /**
     * A setter for slot properties
     * 
     * @param string|Closure|Base $slot
     * @return self
     */
    public function setSlot(string|Closure|Base $slot):self
    {
        if ($slot instanceof Closure) $slot = $slot($this);
        $this->slots[] = $slot;

        return $this;
    }

    /**
     * Set attribute class as method
     * and then assign to attributes property
     * 
     * @param string|Closure $class
     */
    public function setClass(string|Closure $class):self
    {
        if ($class instanceof Closure) $class = $class($this);
        $this->setAttribute('class', $class);
        return $this;
    }

    /**
     * Setter for attributes property
     *
     * @return self
     */
    public function setAttribute():self
    {
        $attribute = ($argument = func_get_args());

        // name and value
        if (func_num_args() == 2) {
            $newAttribute = [];
            // is callable value?
            $newAttribute[$argument[0]] = is_callable($argument[1]) ? $argument[1]($this) : $argument[1];
            $attribute = $newAttribute;
        } else {
            $attribute = $argument[0]??[];
        }

        // Merging current attributes with new attribute
        $this->attributes = array_merge($this->attributes, $attribute);

        return $this;
    }

    /**
     * Make attributes as html format
     *
     * @return string
     */
    public function generateAttribute():string
    {
        $attributes = $this->attributes;
        return implode(' ', array_map(function($attribute) use($attributes) {
            $isByPassedValue = false;
            if (strpos($attribute, '!') !== false) {
                $isByPassedValue = true;
                $attribute = trim($attribute, '!');
            }
            return $this->xssClean($attribute) . '="' . ($isByPassedValue ? $attributes[$attribute] : $this->xssClean($attributes[$attribute])) . '"';
        }, array_keys($attributes)));
    }

    /**
     * Converting slot data to string
     * if it is Base component
     *
     * @return void
     */
    public function generateSlot()
    {
        return implode('', array_map(function($slot) {
            if ($slot instanceof Base) return (string)$slot;
            return $slot;
        }, $this->slots));
    }

    /**
     * A method to set special SLiMS class
     * "notAJAX" in class attribute
     *
     * @param boolean $status
     * @return self
     */
    public function notAjax(bool &$status = false):self
    {
        $class = $this->attributes['class'];
        if (strpos($class, 'notAJAX') === false) {
            $this->attributes['class'] = $class . ' notAJAX';
            $status = true;
        }
        
        return $this;
    }

    /**
     * A method to set openPopUp class
     *
     * @param string $title
     * @param boolean $status
     * @return self
     */
    public function openPopUp(string $title = 'Untitle Pop Up', bool &$status = false):self
    {
        $class = $this->attributes['class'];

        if (strpos($class, 'openPopUp') === false) {
            $withNotAjaxClass = false;
            $this->notAjax($withNotAjaxClass);
            
            if ($withNotAjaxClass) $class = $this->attributes['class'];
            $this->attributes['class'] = $class . ' openPopUp';
            
            if (!isset($this->attributes['width'])) {
                $this->attributes['width'] = 780;
            }

            if (!isset($this->attributes['height'])) {
                $this->attributes['height'] = 500;
            }

            $this->attributes['title'] = $title;
        }
        return $this;
    }

    /**
     * Set hidden input for some invisible
     * data from user
     *
     * @param string $name
     * @param string $value
     * @return self
     */
    public function setHiddenInput(string $name, string $value):self
    {
        if (!isset($this->properties['hidden_input'])) $this->properties['hidden_input'] = [];
        $this->properties['hidden_input'][$name] = $value;
        $this->setSlot((string)createComponent('input', [
            'name' => $name, 
            'type' => 
            'hidden', 
            'value' => $value
        ]));
        
        return $this;
    }

    /**
     * Register custom event
     *
     * @param string $eventName
     * @param Closure $callback
     * @param string $evenType
     * @return self
     */
    public function registerEvent(string $eventName, Closure $callback, string $evenType = ''):self
    {
        $argument = !empty($eventType) ? [$callback, $eventType] : [$callback];
        $this->properties['event']['on_' . strtolower($eventName)] = $argument;
        $this->properties['custom_event_to_call'][] = strtolower($eventName);
        return $this;
    }

    /**
     * Call some event
     * @param  string|array $eventNameOrName
     * @return void
     */
    public function callEvent(string|array $eventNameOrNames)
    {
        if (is_string($eventNameOrNames)) $eventNameOrNames = [$eventNameOrNames];

        if ($this->properties['custom_event_to_call']) {
            $eventNameOrNames = array_merge($this->properties['custom_event_to_call'], $eventNameOrNames);
        }

        $className = strtolower($class = (new \ReflectionClass($this))->getShortName());

        foreach ($eventNameOrNames as $eventName) {
            if (isset($this->properties['event']['on_' . ($eventType = strtolower($eventName))])) {
                $event = $this->properties['event']['on_' . $eventType];

                if (isset($event[1])) {
                    list($event, $eventType) = $event;
                } else { $event = $event[0]; }

                // validated some request
                if (!isset($_REQUEST[$eventType])) continue;

                $bypassDefaultEvent = false;
                Plugins::run($className . '_on_' . $eventType, [$this, &$bypassDefaultEvent]);

                if (!$bypassDefaultEvent) call_user_func_array($event, [$this]);
            }
        }
    }

    /**
     * A magic method to handle special method
     * base on some conditional
     *
     * @param [type] $method
     * @param [type] $argument
     * @return self
     */
    public function __call($method, $argument):self
    {
        if (substr($method, 0,2) === 'on') {
            $eventName = strtolower(substr_replace($method, 'on_', 0,2));
            $this->properties['event'][$eventName] = $argument;
        }

        return $this;
    }

    /**
     * A magic method to generate object
     * as string. Its useful if your object
     * converting to string with echo etc.
     *
     * @return string
     */
    public function __toString()
    {
        $tag = trim($this->xssClean($this->tag));
        $html = '<' . $tag . ' ';
        if ($this->attributes) {
            $html .= $this->generateAttribute();
        }
        if ($this->slots) {
            $html .= '>';
            $html .= $this->generateSlot();
            $html .= '</' . $tag . '>';
        } else {
            $html = in_array($tag, $this->voidElements) ? $html . '/>' : $html . '</' . $tag . '>';
        }

        return $html;
    }
}