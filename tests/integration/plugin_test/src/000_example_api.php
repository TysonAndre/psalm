<?php

namespace {

    class BaseClass
    {
        // NOTE: For anything which is a subclass of BaseClass,
        // if there is an entry for a method name in METHOD_FILTERS (case-insensitive),
        // then the union type of the first parameter of that method will depend on the corresponding entry in METHOD_FILTERS
        const METHOD_FILTERS = [];
    }
    class ExampleAPI extends BaseClass
    {
    }
}

namespace ExampleNamespace {

    class MyClass extends \ExampleAPI
    {
        const METHOD_FILTERS = [
        'myAPIMethod' => [
            // E.g. Union Types
            'field' => 'string',
            'otherField' => 'string[]',
        ],
        ];

        /**
         * @param array $params (Will be overridden by plugin checking METHOD_FILTERS
         * @return void
         */
        public function myAPIMethod(array $params)
        {
            printf("%s\n", $params['field']);
            printf("%s\n", $params['fieldTypo']);
            echo count($params['otherField']);
            echo strlen($params['otherField']);
        }
    }
}
