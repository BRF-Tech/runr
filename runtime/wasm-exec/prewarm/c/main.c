// A RunR wasm-exec function: a WASI command module on Spin's WAGI executor (CGI).
// The request body is on stdin, the request in the environment (REQUEST_METHOD, PATH_INFO,
// QUERY_STRING, CONTENT_TYPE, HTTP_<HEADER>); the response goes to stdout: CGI headers, a
// blank line, the body. Logs go to stderr. Queue messages and schedule ticks arrive as
// CloudEvents: the HTTP_CE_TYPE variable names the event.
#include <stdio.h>
#include <stdlib.h>
#include <string.h>

// value of a query parameter (not URL-decoded), "" when absent
static void query_param(const char *name, char *out, size_t size) {
    const char *q = getenv("QUERY_STRING");
    size_t n = strlen(name);
    out[0] = '\0';
    while (q && *q) {
        if (strncmp(q, name, n) == 0 && q[n] == '=') {
            q += n + 1;
            size_t i = 0;
            while (q[i] && q[i] != '&' && i + 1 < size) {
                out[i] = q[i];
                i++;
            }
            out[i] = '\0';
            return;
        }
        q = strchr(q, '&');
        if (q) q++;
    }
}

int main(void) {
    const char *event = getenv("HTTP_CE_TYPE");
    printf("Content-Type: text/plain\n\n");

    if (event && strcmp(event, "dev.runr.schedule.tick") == 0) {
        fprintf(stderr, "tick from C (Wasm)\n");
        printf("tick from C (Wasm)");
        return 0;
    }
    if (event && strcmp(event, "dev.runr.queue.message") == 0) {
        char body[4096];
        size_t len = fread(body, 1, sizeof body - 1, stdin);
        body[len] = '\0';
        printf("processed %s (from C, Wasm)", body);
        return 0;
    }

    char name[128];
    query_param("name", name, sizeof name);
    printf("Hello, %s! (from C, Wasm)", name[0] ? name : "World");
    return 0;
}
