<?php

// Uploaded images and the upload source of runtimes (docs/images.md)
return [
    'registry_unavailable' => 'Region :region has no ready internal registry: uploaded images are pushed into the region\'s internal (Zot) registry. Set it up in Settings → Regions → Registry.',
    'upload_open' => 'An upload of :name into this region is already running; finish or abort it first.',
    'quota_exceeded' => 'The upload does not fit the image storage of your organization: :used of :limit used, :requested requested. Delete images you no longer need.',
    'bad_name' => 'The image name must be repository[:tag] — lowercase letters, digits and . _ - in up to three /-separated parts, a tag of up to 128 letters, digits and . _ -.',
    'bad_chunk' => 'The chunk at offset :offset does not fit the upload: at most :chunk bytes per chunk, inside the :size bytes of the file.',
    'chunk_failed' => 'The region did not take the chunk (:status): :message',
    'incomplete' => ':received of :size bytes received; send the rest first.',
    'not_ready' => 'The upload is :status and takes no chunks.',
    'receiver_error' => 'The upload receiver in the region answered: :message',
    'receiver_not_created' => 'The upload receiver could not be created in the region.',
    'receiver_stopped' => 'The upload receiver in the region stopped.',
    'receiver_image' => 'The upload receiver image :image could not be pulled in the region (:reason). Regions without internet need it in a registry they reach (RUNR_IMAGE_UPLOAD_IMAGE).',
    'push_failed' => 'The image could not be pushed into the region registry.',
    'push_timeout' => 'The push into the region registry did not finish in time.',
    'expired' => 'The upload was not completed within its time and was aborted.',
    'in_use' => ':name is still used by :used. Point them at another image first.',
    'caas_not_found' => 'The uploaded image :image does not exist in this region (or belongs to another organization). Pick one from the uploaded images of the region.',
    'caas_not_in_registry' => 'The uploaded image :name is not in the registry of region :region: the region moved to another cluster after it was uploaded. Upload it to the region again, then select it.',
    'runtime_upload_missing' => 'Runtime :runtime version :version uses the uploaded image :reference, which is not uploaded in region :region. Upload it there (Settings → Images, or runr images push --region :region).',
    'runtime_upload_regions' => 'names :reference, which is not uploaded in region(s) :regions',
    'runtime_upload_unknown' => 'names :reference, which is not an uploaded image of the runtime\'s owner',
    'wasm_upload_unknown' => 'wasm.upload names :reference, which is not an uploaded WebAssembly module in region :region.',
    'wasm_upload_kind' => 'wasm.upload names :reference, a :kind upload; it must be a WebAssembly module (.wasm).',
];
