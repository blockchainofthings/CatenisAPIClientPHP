<?php
/**
 * Created by claudio on 2018-12-06
 */

namespace Catenis\Internal;

/**
 * Trait PackageTrait
 * @package Catenis\Internal
 */
trait PackageTrait
{
    /**
     * Access a property defined (as protected) in a derived class
     * @param ApiPackage $obj - Instance of the derived class where property is defined
     * @param string $propertyName - The name of the property
     * @return mixed - The property itself
     */
    protected function &accessProperty(ApiPackage $obj, $propertyName)
    {
        return $obj->$propertyName;
    }

    /**
     * Invoke a method defined (as protected) in a derived class
     * @param ApiPackage $obj - Instance of the derived class where method is defined
     * @param string $methodName - The name of the method
     * @param mixed ...$args - The arguments to be passed to the method
     * @return mixed - The value returned by the method
     */
    protected function invokeMethod(ApiPackage $obj, $methodName, ...$args)
    {
        return call_user_func_array([$obj, $methodName], $args);
    }
}
