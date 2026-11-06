<?php

namespace Tests\Unit;

use App\Services\Concerns\KnativeCrdTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * serving-crds.yaml as Knative publishes it: every document opens with its licence comment. A parser
 * that skipped such parts applied no CRD at all, on every version, and said nothing; the operator's
 * own apply covered for it. Once the documents are kept, their empty maps have to survive too:
 * `status: {}` as a PHP array goes out as [], and the API server refuses the CRD.
 */
class KnativeCrdTraitTest extends TestCase
{
    private const CRD = <<<'YAML'
    # Copyright 2020 The Knative Authors
    #
    # Licensed under the Apache License, Version 2.0 (the "License");

    apiVersion: apiextensions.k8s.io/v1
    kind: CustomResourceDefinition
    metadata:
      name: %s
      labels:
        app.kubernetes.io/version: "1.24.0"
        knative.dev/crd-install: "true"
    spec:
      group: serving.knative.dev
      versions:
        - name: v1
          served: true
          storage: true
          subresources:
            status: {}
          schema:
            openAPIV3Schema:
              type: object
              x-kubernetes-preserve-unknown-fields: true
          additionalPrinterColumns: []
      names:
        kind: Example
        plural: examples
        categories:
          - all
          - knative
      scope: Namespaced

    YAML;

    /** @return list<array<string, mixed>> */
    private function parse(string $yaml): array
    {
        return (new class
        {
            use KnativeCrdTrait;

            public function parse(string $yaml): array
            {
                return $this->parseMultiDocYaml($yaml);
            }
        })->parse($yaml);
    }

    private function crd(string $name): string
    {
        return sprintf(self::CRD, $name);
    }

    public function test_a_document_that_opens_with_its_licence_is_kept(): void
    {
        $docs = $this->parse($this->crd('a.serving.knative.dev')."\n---\n".$this->crd('b.serving.knative.dev'));

        $this->assertSame(['a.serving.knative.dev', 'b.serving.knative.dev'], array_map(fn ($doc) => $doc['metadata']['name'], $docs));
        $this->assertSame('CustomResourceDefinition', $docs[0]['kind']);
        $this->assertSame('1.24.0', $docs[0]['metadata']['labels']['app.kubernetes.io/version'], 'a string stays a string');
        $this->assertSame(['all', 'knative'], $docs[0]['spec']['names']['categories']);
    }

    /** @return array<string, array{string, int}> */
    public static function documentsWithoutContent(): array
    {
        return [
            'only comments' => ["# Copyright\n# Licensed\n", 0],
            'nothing' => ['', 0],
            'an empty document between two' => ["kind: A\n---\n---\nkind: B\n", 2],
            'a comment between two' => ["kind: A\n---\n# nothing here\n---\nkind: B\n", 2],
            'an empty map' => ["{}\n", 0],
        ];
    }

    #[DataProvider('documentsWithoutContent')]
    public function test_a_part_without_content_is_no_document(string $yaml, int $documents): void
    {
        $this->assertCount($documents, $this->parse($yaml));
    }

    public function test_an_empty_map_goes_out_as_a_map_and_an_empty_list_as_a_list(): void
    {
        $json = json_encode($this->parse($this->crd('a.serving.knative.dev'))[0]);

        $this->assertStringContainsString('"subresources":{"status":{}}', $json);
        $this->assertStringContainsString('"additionalPrinterColumns":[]', $json);
    }
}
