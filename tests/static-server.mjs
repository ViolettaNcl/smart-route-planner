import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
import { extname, join, normalize, resolve, sep } from 'node:path';

const root = resolve(process.cwd(), 'public');
const types = {
    '.css': 'text/css; charset=utf-8',
    '.js': 'text/javascript; charset=utf-8',
    '.json': 'application/json; charset=utf-8',
    '.webmanifest': 'application/manifest+json; charset=utf-8',
    '.svg': 'image/svg+xml',
    '.png': 'image/png',
    '.ico': 'image/x-icon',
    '.xml': 'application/xml; charset=utf-8',
    '.txt': 'text/plain; charset=utf-8',
};

function renderIndex(source) {
    return source
        .replace(/<\?php[\s\S]*?\?>/g, '')
        .replace(/<\?=[\s\S]*?\?>/g, '');
}

const server = createServer(async (request, response) => {
    const pathname = decodeURIComponent(new URL(request.url, 'http://127.0.0.1').pathname);
    if (pathname === '/api/health.php') {
        response.writeHead(200, { 'content-type': 'application/json' });
        response.end('{"ok":true}');
        return;
    }

    try {
        if (pathname === '/' || pathname === '/index.php') {
            const source = await readFile(join(root, 'index.php'), 'utf8');
            response.writeHead(200, { 'content-type': 'text/html; charset=utf-8' });
            response.end(renderIndex(source));
            return;
        }

        const relativePath = normalize(pathname).replace(/^[/\\]+/, '');
        const file = resolve(root, relativePath);
        if (file !== root && !file.startsWith(root + sep)) throw new Error('unsafe path');
        const body = await readFile(file);
        response.writeHead(200, { 'content-type': types[extname(file)] || 'application/octet-stream' });
        response.end(body);
    } catch (error) {
        response.writeHead(404, { 'content-type': 'text/plain; charset=utf-8' });
        response.end('Not found');
    }
});

server.listen(8088, '127.0.0.1', () => {
    console.log('Static test server: http://127.0.0.1:8088');
});

process.on('SIGTERM', () => server.close());
