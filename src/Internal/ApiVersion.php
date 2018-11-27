<?php
/**
 * Created by claudio on 2018-11-21
 */

namespace Catenis\Internal;

use Exception;


class ApiVersion {
    public $major;
    public $minor;

    private static $verPattern = '/^(\d+)\.(\d+)$/';

    /**
     * Checks if version is valid
     * @param string|ApiVersion $ver
     * @return bool
     */
    private static function isValidVersion($ver) {
        return (gettype($ver) === 'string' && preg_match(ApiVersion::$verPattern, $ver)) || ($ver instanceOf ApiVersion);
    }

    /**
     * Checks if a version is valid and returns it as an API version object
     * @param string|ApiVersion $ver
     * @param bool $reportError
     * @return ApiVersion|null - Returns the encapsulated version or null if it is not valid
     * @throws Exception
     */
    public static function checkVersion($ver, $reportError = false) {
        if (ApiVersion::isValidVersion($ver)) {
                return gettype($ver) === 'string' ? new ApiVersion($ver) : $ver;
        }
        else if ($reportError) {
            throw new Exception("Invalid API version: $ver");
        }

        return null;
    }

    /**
     * ApiVersion constructor.
     * @param string|ApiVersion $ver - The version number to use
     * @throws Exception
     */
    public function __construct($ver) {
        if (!self::isValidVersion($ver)) {
            throw new Exception("Invalid API version: $ver");
        }

        if (gettype($ver) === 'string') {
            // Passed version is a string; parse it
            preg_match(self::$verPattern, $ver, $matches);

            $this->major = (integer)$matches[1];
            $this->minor = (integer)$matches[2];
        }
        else {
            // Passed version is an ApiVersion instance; just copy its properties over
            $this->major = $ver->major;
            $this->minor = $ver->minor;
        }
    }

    public function __toString() {
        return "$this->major.$this->minor";
    }

    /**
     * Test if this version is equal to another version
     * @param string|ApiVersion $ver
     * @return bool
     * @throws Exception
     */
    public function eq($ver) {
        $ver = ApiVersion::checkVersion($ver);

        return $this->major === $ver->major && $this->minor === $ver->minor;
    }

    /**
     * Test if this version is not equal to another version
     * @param string|ApiVersion $ver
     * @return bool
     * @throws Exception
     */
    public function ne($ver) {
        $ver = ApiVersion::checkVersion($ver);

        return $this->major !== $ver->major || $this->minor !== $ver->minor;
    }

    /**
     * Test if this version is greater than another version
     * @param string|ApiVersion $ver
     * @return bool
     * @throws Exception
     */
    public function gt($ver) {
        $ver = ApiVersion::checkVersion($ver);

        return $this->major > $ver->major || ($this->major === $ver->major && $this->minor > $ver->minor);
    }

    /**
     * Test if this version is less than another version
     * @param string|ApiVersion $ver
     * @return bool
     * @throws Exception
     */
    public function lt($ver) {
        $ver = ApiVersion::checkVersion($ver);

        return $this->major < $ver->major || ($this->major === $ver->major && $this->minor < $ver->minor);
    }

    /**
     * Test if this version is greater than or equal to another version
     * @param string|ApiVersion $ver
     * @return bool
     * @throws Exception
     */
    public function gte($ver) {
        $ver = ApiVersion::checkVersion($ver);

        return $this->major > $ver->major || ($this->major === $ver->major && ($this->minor > $ver->minor || $this->minor === $ver->minor));
    }

    /**
     * Test if this version is less than or equal to another version
     * @param string|ApiVersion $ver
     * @return bool
     * @throws Exception
     */
    public function lte($ver) {
        $ver = ApiVersion::checkVersion($ver);

        return $this->major < $ver->major || ($this->major === $ver->major && ($this->minor < $ver->minor || $this->minor === $ver->minor));
    }
}
