<?php

/*
 * This file is part of the NelmioApiDocBundle.
 *
 * (c) Nelmio <hello@nelm.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Nelmio\ApiDocBundle\Parser;

use Nelmio\ApiDocBundle\DataTypes;
use Symfony\Component\Validator\Exception\ConstraintDefinitionException;
use Symfony\Component\Validator\Mapping\Factory\MetadataFactoryInterface;
use Symfony\Component\Validator\MetadataFactoryInterface as LegacyMetadataFactoryInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\Type;

/**
 * Uses the Symfony Validation component to extract information about API objects.
 */
class ValidationParser implements ParserInterface, PostParserInterface
{
    /**
     * @var \Symfony\Component\Validator\MetadataFactoryInterface
     */
    protected $factory;

    protected $typeMap = array(
        'integer'  => DataTypes::INTEGER,
        'int'      => DataTypes::INTEGER,
        'scalar'   => DataTypes::STRING,
        'numeric'  => DataTypes::INTEGER,
        'boolean'  => DataTypes::BOOLEAN,
        'string'   => DataTypes::STRING,
        'float'    => DataTypes::FLOAT,
        'double'   => DataTypes::FLOAT,
        'long'     => DataTypes::INTEGER,
        'object'   => DataTypes::MODEL,
        'array'    => DataTypes::COLLECTION,
        'DateTime' => DataTypes::DATETIME,
    );

    /**
     * Requires a validation MetadataFactory.
     *
     * @param MetadataFactoryInterface|LegacyMetadataFactoryInterface $factory
     */
    public function __construct($factory)
    {
        if (!($factory instanceof MetadataFactoryInterface) && !($factory instanceof LegacyMetadataFactoryInterface)) {
            throw new \InvalidArgumentException('Argument 1 of %s constructor must be either an instance of Symfony\Component\Validator\Mapping\Factory\MetadataFactoryInterface or Symfony\Component\Validator\MetadataFactoryInterface.');
        }
        $this->factory = $factory;
    }

    /**
     * {@inheritdoc}
     */
    public function supports(array $input)
    {
        $className = $input['class'];

        return $this->factory->hasMetadataFor($className);
    }

    /**
     * {@inheritdoc}
     */
    public function parse(array $input)
    {
        $className = $input['class'];
        $groups = isset($input['groups']) ? $input['groups'] : array();

        $parsed = $this->doParse($className, array(), $groups);

        if (isset($input['name']) && !empty($input['name'])) {
            $output = array();
            $output[$input['name']] = array(
                'dataType' => 'object',
                'actualType' => 'object',
                'class' => $className,
                'subType' => null,
                'required' => null,
                'description' => null,
                'readonly' => null,
                'children' => $parsed
            );

            return $output;
        }

        return $parsed;
    }

    /**
     * Recursively parse constraints.
     *
     * @param  $className
     * @param  array $visited
     * @param  array $groups Only validations in given groups will be print.
     * @return array
     */
    protected function doParse($className, array $visited, array $groups=array())
    {
        $params = array();
        $classdata = $this->factory->getMetadataFor($className);
        $properties = $classdata->getConstrainedProperties();

        $refl = $classdata->getReflectionClass();
        $defaults = $refl->getDefaultProperties();

        foreach ($properties as $property) {
            $vparams = array();

            $vparams['default'] = isset($defaults[$property]) ? $defaults[$property] : null;

            $pds = $classdata->getPropertyMetadata($property);
            foreach ($pds as $propdata) {
                $constraints = $propdata->getConstraints();

                foreach ($constraints as $constraint) {
                    if (!empty($groups)) {
                        $doParse = false;
                        foreach ($groups as $group) {
                            if (isset($constraint->groups) and in_array($group, $constraint->groups)) {
                                $doParse = true;
                            }
                        }
                    } else {
                        $doParse = true;
                    }

                    if ($doParse) {
                        $vparams = $this->parseConstraint($constraint, $vparams, $className, $visited, $groups);
                    }
                }
            }

            if (isset($vparams['format'])) {
                $vparams['format'] = join(', ', $vparams['format']);
            }

            foreach (array('dataType', 'readonly', 'required', 'subType') as $reqprop) {
                if (!isset($vparams[$reqprop])) {
                    $vparams[$reqprop] = null;
                }
            }

            // check for nested classes with All constraint
            if (isset($vparams['class']) && !in_array($vparams['class'], $visited) && null !== $this->factory->getMetadataFor($vparams['class'])) {
                $visited[] = $vparams['class'];
                $vparams['children'] = $this->doParse($vparams['class'], $visited, $groups);
            }

            $vparams['actualType'] = isset($vparams['actualType']) ? $vparams['actualType'] : DataTypes::STRING;

            $params[$property] = $vparams;
        }

        return $params;
    }

    /**
     * {@inheritDoc}
     */
    public function postParse(array $input, array $parameters)
    {
        foreach ($parameters as $param => $data) {
            if (isset($data['class']) && isset($data['children'])) {
                $input = array('class' => $data['class']);
                $parameters[$param]['children'] = array_merge(
                    $parameters[$param]['children'], $this->postParse($input, $parameters[$param]['children'])
                );
                $parameters[$param]['children'] = array_merge(
                    $parameters[$param]['children'], $this->parse($input, $parameters[$param]['children'])
                );
            }
        }

        return $parameters;
    }

    /**
     * Create a valid documentation parameter based on an individual validation Constraint.
     * Currently supports:
     *  - NotBlank/NotNull
     *  - Type
     *  - Email
     *  - Url
     *  - Ip
     *  - Length (min and max)
     *  - Choice (single and multiple, min and max)
     *  - Regex (match and non-match)
     *
     * @param  Constraint $constraint The constraint metadata object.
     * @param  array      $vparams    The existing validation parameters.
     * @param  array      $groups     Only validations in given groups will be print.
     * @return mixed      The parsed list of validation parameters.
     */
    protected function parseConstraint(Constraint $constraint, $vparams, $className, &$visited = array(), array $groups=array())
    {
        $class = substr(get_class($constraint), strlen('Symfony\\Component\\Validator\\Constraints\\'));

        $vparams['actualType'] = DataTypes::STRING;
        $vparams['subType'] = null;

        switch ($class) {
            case 'NotBlank':
                $vparams['format'][] = '{not blank}';
            case 'NotNull':
                $vparams['required'] = true;
                break;
            case 'Type':
                if (isset($this->typeMap[$constraint->type])) {
                    $vparams['actualType'] = $this->typeMap[$constraint->type];
                }
                $vparams['dataType'] = $constraint->type;
                break;
            case 'Email':
                $vparams['format'][] = '{email address}';
                break;
            case 'Url':
                $vparams['format'][] = '{url}';
                break;
            case 'Ip':
                $vparams['format'][] = '{ip address}';
                break;
            case 'Date':
                $vparams['format'][] = '{Date YYYY-MM-DD}';
                $vparams['actualType'] = DataTypes::DATE;
                break;
            case 'DateTime':
                $vparams['format'][] = '{DateTime YYYY-MM-DD HH:MM:SS}';
                $vparams['actualType'] = DataTypes::DATETIME;
                break;
            case 'Time':
                $vparams['format'][] = '{Time HH:MM:SS}';
                $vparams['actualType'] = DataTypes::TIME;
                break;
            case 'Length':
                $messages = array();
                if (isset($constraint->min)) {
                    $messages[] = "min: {$constraint->min}";
                }
                if (isset($constraint->max)) {
                    $messages[] = "max: {$constraint->max}";
                }
                $vparams['format'][] = '{length: ' . join(', ', $messages) . '}';
                break;
            case 'Choice':
                $choices = $this->getChoices($constraint, $className);
                $format = '[' . join('|', $choices) . ']';
                if ($constraint->multiple) {
                    $vparams['actualType'] = DataTypes::COLLECTION;
                    $vparams['subType'] = DataTypes::ENUM;
                    $messages = array();
                    if (isset($constraint->min)) {
                        $messages[] = "min: {$constraint->min} ";
                    }
                    if (isset($constraint->max)) {
                        $messages[] = "max: {$constraint->max} ";
                    }
                    $vparams['format'][] = '{' . join ('', $messages) . 'choice of ' . $format . '}';
                } else {
                    $vparams['actualType'] = DataTypes::ENUM;
                    $vparams['format'][] = $format;
                }
                break;
            case 'Collection':
                foreach ($constraint->fields as $field => $constraints) {
                    $fieldParams = array();
                    foreach ($constraints->constraints as $constraint) {
                        $fieldParams = $this->parseConstraint($constraint, $fieldParams, $className, $visited);
                    }
                    $vparams['format'][] = $field . ': [' . join(',', $fieldParams['format']) . ']';
                }
                break;
            case 'Count':
                $messages = array();
                if (isset($constraint->min)) {
                    $messages[] = "min: {$constraint->min}";
                }
                if (isset($constraint->max)) {
                    $messages[] = "max: {$constraint->max}";
                }
                $vparams['format'][] = '{Count: ' . join(', ', $messages) . '}';
                break;
            case 'File':
                $messages = array();
                if (isset($constraint->maxSize)) {
                    $messages[] = "Max Size: {$constraint->maxSize}";
                }
                if (isset($constraint->mimeTypes)) {
                    $mimeTypes = is_array($constraint->mimeTypes)
                        ? '[' . join(',', $constraint->mimeTypes) . ']' : $constraint->mimeTypes;
                    $messages[] = "Mime Types: $mimeTypes";
                }
                $vparams['format'][] = '{File: ' . join(', ', $messages) . '}';
                break;
            case 'Image':
                $messages = array();
                if (isset($constraint->maxSize)) {
                    $messages[] = "Max Size: {$constraint->maxSize}";
                }
                if (isset($constraint->mimeTypes)) {
                    $mimeTypes = is_array($constraint->mimeTypes)
                        ? '[' . join(',', $constraint->mimeTypes) . ']' : $constraint->mimeTypes;
                    $messages[] = "Mime Types: {$mimeTypes}";
                }
                if (isset($constraint->minWidth)) {
                    $messages[] = "Min Width: {$constraint->minWidth}";
                }
                if (isset($constraint->maxWidth)) {
                    $messages[] = "Max Width: {$constraint->maxWidth}";
                }
                if (isset($constraint->minHeight)) {
                    $messages[] = "Min Height: {$constraint->minHeight}";
                }
                if (isset($constraint->maxHeight)) {
                    $messages[] = "Max Height: {$constraint->maxHeight}";
                }
                if (isset($constraint->maxRatio)) {
                    $messages[] = "Max Ratio: {$constraint->maxRatio}";
                }
                if (isset($constraint->minRatio)) {
                    $messages[] = "Min Ratio: {$constraint->minRatio}";
                }
                if (isset($constraint->allowLandscape)) {
                    $messages[] = "Allow Landscape: " . ($constraint->allowLandscape ? 'True' : 'False');
                }
                if (isset($constraint->allowPortrait)) {
                    $messages[] = "Allow Portrait: " . ($constraint->allowPortrait ? 'True' : 'False');
                }
                $vparams['format'][] = '{File: ' . join(', ', $messages) . '}';
                break;
            case 'Regex':
                if ($constraint->match) {
                    $vparams['format'][] = '{match: ' . $constraint->pattern . '}';
                } else {
                    $vparams['format'][] = '{not match: ' . $constraint->pattern . '}';
                }
                break;
            case 'Range':
                $messages = array();
                if (isset($constraint->min)) {
                    $messages[] = "min: {$constraint->min}";
                }
                if (isset($constraint->max)) {
                    $messages[] = "max: {$constraint->max}";
                }
                $vparams['format'][] = '{Range: ' . join(', ', $messages) . '}';
                break;
            case 'All':
                foreach ($constraint->constraints as $childConstraint) {
                    if ($childConstraint instanceof Type) {
                        $nestedType = $childConstraint->type;
                        $exp = explode("\\", $nestedType);
                        if (!class_exists($nestedType)) {
                            $nestedType = substr($className, 0, strrpos($className, '\\') + 1).$nestedType;

                            if (!class_exists($nestedType)) {
                                continue;
                            }
                        }

                        $vparams['dataType']   = sprintf("array of objects (%s)", end($exp));
                        $vparams['actualType'] = DataTypes::COLLECTION;
                        $vparams['subType']    = $nestedType;
                        $vparams['class']      = $nestedType;

                        if (!in_array($nestedType, $visited)) {
                            $visited[] = $nestedType;
                            $vparams['children'] = $this->doParse($nestedType, $visited, $groups);
                        }
                    }
                }
                break;
        }

        return $vparams;
    }

    /**
     * Return Choice constraint choices.
     *
     * @param  Constraint                                                           $constraint
     * @param $className
     * @return array
     * @throws \Symfony\Component\Validator\Exception\ConstraintDefinitionException
     */
    protected function getChoices(Constraint $constraint, $className)
    {
        if ($constraint->callback) {
            if (is_callable(array($className, $constraint->callback))) {
                $choices = call_user_func(array($className, $constraint->callback));
            } elseif (is_callable($constraint->callback)) {
                $choices = call_user_func($constraint->callback);
            } else {
                throw new ConstraintDefinitionException('The Choice constraint expects a valid callback');
            }
        } else {
            $choices = $constraint->choices;
        }

        return $choices;
    }
}
