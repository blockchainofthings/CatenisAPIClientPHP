<?php
/**
 * Created by claudio on 2018-12-05
 */

namespace Catenis\Internal;


/**
 * Class ApiPackage - Base class used to group distinct classes of the Catenis API client so they
 *                     can access non-public (protected) properties and methods of one another
 * @package Catenis\Internal
 */
abstract class ApiPackage {
    use PackageTrait;
}