# Firebase Studio workspace for testing the app.
# Docs: https://firebase.google.com/docs/studio/customize-workspace
{ pkgs, ... }: {
  channel = "unstable";

  packages = [
    pkgs.php85
    pkgs.php85Packages.composer
    pkgs.nodejs_24
    pkgs.postgresql_17
  ];

  env = {
    PHP_CLI_SERVER_WORKERS = "4";
  };

  idx = {
    extensions = [ ];

    workspace = {
      # Runs once, when the workspace is created.
      onCreate = {
        setup = "bash .idx/setup.sh";
      };
    };

    previews = {
      enable = true;
      previews = {
        web = {
          command = [ "bash" ".idx/serve.sh" "$PORT" ];
          manager = "web";
        };
      };
    };
  };
}
