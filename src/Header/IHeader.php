<?php
/**
 * This file is part of the ZBateson\MailMimeParser project.
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */
namespace ZBateson\MailMimeParser\Header;

/**
 * A mime email header line consisting of a name and value.
 *
 * The header object provides methods to access the header's name, raw value,
 * and also its parsed value.  The parsed value will depend on the type of
 * header and in some cases may be broken up into other parts (for example email
 * addresses in an address header, or parameters in a parameter header).
 *
 * @author Zaahid Bateson
 */
interface IHeader
{
    /**
     * Returns an array of IHeaderPart objects the header's value has been
     * parsed into.
     *
     * @return IHeaderPart[]
     */
    public function getParts();

    /**
     * Returns the parsed value of the header -- calls getValue on $this->part
     *
     * @return string
     */
    public function getValue();

    /**
     * Returns the raw value of the header prior to any processing.
     *
     * @return string
     */
    public function getRawValue();

    /**
     * Returns the name of the header.
     *
     * @return string
     */
    public function getName();

    /**
     * Returns the string representation of the header.
     *
     * i.e.: '<HeaderName>: <RawValue>'
     *
     * @return string
     */
    public function __toString();
}
