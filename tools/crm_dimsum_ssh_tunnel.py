"""Túnel SSH privado para conectar herramientas locales al PostgreSQL del VPS.

La contraseña SSH se recibe solo mediante la variable de entorno
CRM_DIMSUM_SSH_PASSWORD; no queda almacenada en este archivo.
"""

import os
import select
import socket
import threading

import paramiko


VPS_HOST = "2.25.155.29"
VPS_USER = "root"
REMOTE_DB_HOST = "172.18.0.2"
REMOTE_DB_PORT = 5432
LOCAL_HOST = "127.0.0.1"
LOCAL_PORT = 5433


def relay(client_socket: socket.socket, transport: paramiko.Transport) -> None:
    channel = transport.open_channel(
        "direct-tcpip",
        (REMOTE_DB_HOST, REMOTE_DB_PORT),
        client_socket.getpeername(),
    )

    try:
        while True:
            ready, _, _ = select.select([client_socket, channel], [], [])

            if client_socket in ready:
                data = client_socket.recv(32768)
                if not data:
                    break
                channel.sendall(data)

            if channel in ready:
                data = channel.recv(32768)
                if not data:
                    break
                client_socket.sendall(data)
    finally:
        channel.close()
        client_socket.close()


def main() -> None:
    password = os.environ.get("CRM_DIMSUM_SSH_PASSWORD")
    if not password:
        raise RuntimeError("Falta CRM_DIMSUM_SSH_PASSWORD.")

    client = paramiko.SSHClient()
    client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    client.connect(VPS_HOST, username=VPS_USER, password=password, timeout=20)
    transport = client.get_transport()

    listener = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    listener.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    listener.bind((LOCAL_HOST, LOCAL_PORT))
    listener.listen(25)

    while True:
        client_socket, _ = listener.accept()
        threading.Thread(target=relay, args=(client_socket, transport), daemon=True).start()


if __name__ == "__main__":
    main()
