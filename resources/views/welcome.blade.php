<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>API DEVELOPMENT </title>
  </head>
  <body>
    <form action="{{ url('category/store') }}" method="post">
        @csrf
        <div class="mb-3">
          <label for="exampleInputEmail1" class="form-label">Name </label>
          <input type="text" name="name" class="form-control" id="exampleInputEmail1" aria-describedby="emailHelp">

        </div>
        <div class="mb-3">
          <label for="exampleInputPassword1" class="form-label">Status</label>
          <input type="text" name="status" class="form-control" id="exampleInputPassword1">
        </div>

        <button type="submit" class="btn btn-primary">Submit</button>
      </form>
  </body>
</html>
