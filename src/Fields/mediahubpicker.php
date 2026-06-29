<?php

/**
 * PHP definition for the 'mediahubpicker' custom field type.
 *
 * The Vue component (k-mediahubpicker-field) is registered in index.js.
 * This file declares the server-side props, save format, and value restore.
 */
return [

    'props' => [
        'accept' => function (string $accept = '') {
            return $accept;
        },
        'multiple' => function (bool $multiple = true) {
            return $multiple;
        },

        // Called both on page render (value = stored YAML string)
        // and during save (value = array of file objects from Vue).
        'value' => function ($value = null) {
            if (empty($value)) {
                return [];
            }

            $kirby = \Kirby\Cms\App::instance();

            // Determine the list of file:// references
            if (is_array($value)) {
                // Vue passed an array of {uuid, filename, ...} objects (save flow)
                $refs = [];
                foreach ($value as $item) {
                    if (is_array($item) && isset($item['uuid']) && !empty($item['uuid'])) {
                        $refs[] = $item['uuid'];
                    } elseif (is_string($item) && !empty(trim($item))) {
                        $refs[] = $item;
                    }
                }
            } else {
                // Stored YAML string from .txt file (page render flow)
                $refs = \Kirby\Data\Yaml::decode((string) $value);
                if (!is_array($refs)) {
                    return [];
                }
            }

            $slug = $kirby->option('kirbycode.media-hub.root-slug', 'media-hub');

            // Resolve each file:// UUID to a full file object for Vue
            $result = [];
            foreach ($refs as $ref) {
                if (!is_string($ref) || trim($ref) === '') {
                    continue;
                }
                if (!str_starts_with($ref, 'file://')) {
                    continue;
                }

                $file = $kirby->file($ref);
                if (!$file) {
                    continue;
                }

                $parentId = $file->parent()->id();
                if ($parentId !== $slug && !str_starts_with($parentId, $slug . '/')) {
                    continue;
                }

                $thumb = null;
                if ($file->type() === 'image') {
                    try {
                        $thumb = $file->thumb(['width' => 200])->url();
                    } catch (\Throwable $e) {
                        $thumb = $file->url();
                    }
                }

                $result[] = [
                    'uuid'     => $ref,
                    'filename' => $file->filename(),
                    'url'      => $file->url(),
                    'thumb'    => $thumb,
                    'type'     => $file->type(),
                    'title'    => (string) $file->content()->get('title')->or($file->filename())->value(),
                ];
            }

            return $result;
        },
    ],

    // Convert Vue's array of {uuid, ...} objects to a YAML list of file:// URIs.
    // Each UUID is resolved and verified to belong to the Media Hub before saving.
    'save' => function ($value = null) {
        if (empty($value)) {
            return null;
        }

        $kirby = \Kirby\Cms\App::instance();
        $slug  = $kirby->option('kirbycode.media-hub.root-slug', 'media-hub');
        $uuids = [];

        foreach ((array) $value as $item) {
            $uuid = null;
            if (is_array($item) && !empty($item['uuid'])) {
                $uuid = (string) $item['uuid'];
            } elseif (is_string($item) && trim($item) !== '') {
                $uuid = trim($item);
            }

            if (!$uuid || !str_starts_with($uuid, 'file://')) {
                continue;
            }

            $file = $kirby->file($uuid);
            if (!$file) {
                continue;
            }

            $parentId = $file->parent()->id();
            if ($parentId !== $slug && !str_starts_with($parentId, $slug . '/')) {
                continue;
            }

            $uuids[] = $uuid;
        }

        if (empty($uuids)) {
            return null;
        }

        return \Kirby\Data\Yaml::encode(array_values(array_unique($uuids)));
    },

    'validations' => ['max', 'min'],

];
