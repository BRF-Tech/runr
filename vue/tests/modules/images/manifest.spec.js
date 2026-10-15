import { describe, it, expect } from 'vitest'
import { appendUploadVersion, uploadReferences } from '@/modules/images/manifest'

const MANIFEST = `apiVersion: runr.sh/v1
kind: Runtime
id: bash-airgap
name: Bash
extensions: [sh]
versions:
  - version: "5.2"
    image: docker.io/library/bash:5.2
    default: true

# how it runs
run:
  mode: exec
  command: ["bash", "/app/code/{file}"]
`

describe('appendUploadVersion', () => {
  it('adds the version at the end of the versions list, the rest untouched', () => {
    const out = appendUploadVersion(MANIFEST, { version: '5.3', upload: 'bash-airgap:5.3' })
    expect(out).toContain(`    default: true
  - version: "5.3"
    upload: bash-airgap:5.3

# how it runs
run:`)
    expect(out.match(/^versions:/gm)).toHaveLength(1)
  })

  it('marks it as default when asked and creates the list when there is none', () => {
    const out = appendUploadVersion('id: x\nname: X\n', { version: '1', upload: 'x:1', makeDefault: true })
    expect(out).toBe('id: x\nname: X\nversions:\n  - version: "1"\n    upload: x:1\n    default: true\n')

    const flow = appendUploadVersion('id: x\nversions: []\nrun:\n  mode: http\n', { version: '1', upload: 'x:1' })
    expect(flow).toBe('id: x\nversions:\n  - version: "1"\n    upload: x:1\nrun:\n  mode: http\n')
  })

  it('lists the upload references of a validated manifest', () => {
    expect(uploadReferences({ versions: [{ upload: 'a:1' }, { image: 'b' }, { upload: 'c@sha256:00' }] })).toEqual(['a:1', 'c@sha256:00'])
    expect(uploadReferences(null)).toEqual([])
  })
})
